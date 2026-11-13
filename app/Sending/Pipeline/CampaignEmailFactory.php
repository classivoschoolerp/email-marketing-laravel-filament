<?php

declare(strict_types=1);

namespace App\Sending\Pipeline;

use App\Models\Campaign;
use App\Models\CampaignVariant;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Sending\Message;
use App\Services\Content\AmpValidator;
use App\Services\Content\MergeTags;
use App\Services\Content\PlainTextGenerator;
use App\Services\Content\Spintax;
use App\Services\Lists\PublicPageRenderer;
use App\Services\Sending\ListUnsubscribeHeaders;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds the fully personalised, transport-agnostic Message for one
 * (campaign, subscriber) pair — the heart of the WP-13 send path:
 *
 *  merge tags (§4.1) → spintax (D-55 seed = subscriber uid : campaign uid) →
 *  CAN-SPAM footer (list company, §4.5) → click rewrite + UTM + open pixel
 *  (§1.7) → plain text (auto / override / only) → CID image embedding →
 *  List-Unsubscribe headers (RFC 8058) + campaign X-headers + attachments.
 *
 * Merge tags render BEFORE spintax so `{FNAME|Friend}` default-modifiers are
 * consumed as tags, never mistaken for spintax groups (see MergeTags).
 */
class CampaignEmailFactory
{
    public function __construct(
        private readonly MergeTags $mergeTags,
        private readonly Spintax $spintax,
        private readonly PlainTextGenerator $plainText,
        private readonly CampaignUrlRewriter $rewriter,
        private readonly EmbeddedImageInliner $inliner,
        private readonly ListUnsubscribeHeaders $listUnsubscribe,
        private readonly PublicPageRenderer $pages,
    ) {}

    public function make(Campaign $campaign, Subscriber $subscriber, ?CampaignVariant $variant = null): Message
    {
        /** @var EmailList|null $list */
        $list = $campaign->list()->withoutGlobalScopes()->first();

        $seed = $subscriber->uid.':'.$campaign->uid; // D-55 send seed
        $values = $this->mergeTags->values($campaign, $list, $subscriber);

        $render = fn (string $content): string => $this->spintax->render(
            $this->mergeTags->render($content, $values, $seed),
            $seed,
        );

        // WP-35 §2.3.6: an A/B variant overrides only the columns its test
        // variables own; every other field still comes from the campaign
        // (CampaignVariant::effective()).
        $subjectSource = $variant?->effective($campaign, 'subject') ?? $campaign->subject;
        $fromNameSource = $variant?->effective($campaign, 'from_name') ?? $campaign->from_name;
        $preheaderSource = $variant?->effective($campaign, 'preheader') ?? $campaign->preheader;
        $htmlSource = $variant?->effective($campaign, 'content_html') ?? $campaign->content_html;

        $subject = $render((string) $subjectSource);
        $html = $render((string) $htmlSource);

        // Preview text (F-2.3.2-009): a hidden preheader block is the only way
        // the stored value can reach an inbox preview pane. Injected only when
        // one is set, so a campaign without a preheader renders byte-identical
        // to before.
        $html = $this->prependPreheader($html, trim($render((string) $preheaderSource)));

        // CAN-SPAM footer when the list's company block is filled (§4.5).
        if ($list !== null) {
            $html = $this->appendFooter($html, $this->pages->footer($list));
        }

        $utm = $campaign->utm_enabled
            ? array_map(
                fn ($value): string => $this->mergeTags->render((string) $value, $values, $seed),
                (array) ($campaign->utm_params ?? []),
            )
            : [];

        $html = $this->rewriter->process(
            $campaign,
            $subscriber,
            $html,
            $utm,
            injectPixel: ! $campaign->plain_text_only,
        );

        [$html, $text] = $this->bodies($campaign, $html, $render);

        $embedded = [];

        if ($html !== null && $campaign->embed_images) {
            [$html, $embedded] = $this->inliner->inline($campaign, $html);
        }

        // §2.5.4 F-2.5.4-014 (WP-46): the AMP alternative. Personalised
        // through the same render pipeline as the HTML body, then re-validated
        // AFTER rendering — a merge tag can push a document over the size limit
        // or inject markup AMP forbids, and an invalid AMP part makes Gmail
        // reject the whole message. Invalid or absent ⇒ null ⇒ MimeBuilder
        // assembles exactly the message it always did.
        $amp = $this->ampBody($campaign, $render);

        return new Message(
            fromEmail: $this->mergeTags->render((string) $campaign->from_email, $values, $seed),
            fromName: $this->stringOrNull($this->mergeTags->render((string) $fromNameSource, $values, $seed)),
            to: $this->to($campaign, $subscriber, $values, $seed),
            subject: $subject,
            html: $html,
            text: $text,
            headers: $this->headers($campaign, $subscriber, $values, $seed),
            attachments: $this->attachments($campaign),
            replyTo: $this->stringOrNull((string) $campaign->reply_to),
            embedded: $embedded,
            amp: $amp,
        );
    }

    /**
     * The personalised AMP body, or null.
     *
     * Null in every one of these cases, all of them meaning "send exactly as
     * before": the campaign has no AMP body; the stored body does not validate
     * (AmpValidator); the body stops validating once merge tags are rendered;
     * or the campaign is plain-text-only, where an AMP alternative would
     * contradict the customer's explicit choice.
     *
     * @param  callable(string): string  $render
     */
    private function ampBody(Campaign $campaign, callable $render): ?string
    {
        $amp = trim((string) ($campaign->amp_html ?? ''));

        if ($amp === '' || $campaign->plain_text_only) {
            return null;
        }

        $validator = app(AmpValidator::class);

        if (! $validator->valid($amp)) {
            return null;
        }

        $rendered = $render($amp);

        return $validator->valid($rendered) ? $rendered : null;
    }

    /**
     * html/text pair per the plain-text options (F-2.3.4-014/015/016):
     * plain_text_only drops the html part entirely; auto_plain_text derives
     * text from the final (tracked) html; an explicit plain_text override is
     * itself merge-tag + spintax rendered.
     *
     * @param  callable(string): string  $render
     * @return array{0: ?string, 1: ?string}
     */
    private function bodies(Campaign $campaign, string $html, callable $render): array
    {
        $override = trim((string) $campaign->plain_text) !== ''
            ? $render((string) $campaign->plain_text)
            : null;

        if ($campaign->plain_text_only) {
            return [null, $override ?? $this->plainText->generate($html)];
        }

        if ($campaign->auto_plain_text) {
            return [$html, $this->plainText->generate($html)];
        }

        return [$html, $override];
    }

    /** @return array<string, string>|list<string> */
    private function to(Campaign $campaign, Subscriber $subscriber, array $values, string $seed): array
    {
        $toName = trim($this->mergeTags->render((string) $campaign->to_name, $values, $seed));

        return $toName !== '' ? [$subscriber->email => $toName] : [$subscriber->email];
    }

    /** @return array<string, string> */
    private function headers(Campaign $campaign, Subscriber $subscriber, array $values, string $seed): array
    {
        // RFC 8058 one-click unsubscribe (§4.5 — Gmail/Yahoo bulk rules).
        $headers = $this->listUnsubscribe->headersFor($subscriber);

        // Bounce/FBL attribution seam (WP-15 reads these back from the DSN).
        $headers['X-EMP-Campaign-Uid'] = (string) $campaign->uid;
        $headers['X-EMP-Subscriber-Uid'] = (string) $subscriber->uid;

        // Campaign custom X-headers (F-2.3.4-018), values tag-rendered.
        foreach ((array) ($campaign->custom_headers ?? []) as $header) {
            $name = trim((string) ($header['name'] ?? ''));

            if ($name !== '') {
                $headers[$name] = $this->mergeTags->render((string) ($header['value'] ?? ''), $values, $seed);
            }
        }

        return $headers;
    }

    /** @return list<array{name: string, content: string, mime?: string}> */
    private function attachments(Campaign $campaign): array
    {
        $out = [];

        foreach ($campaign->attachments()->get() as $attachment) {
            try {
                if ($attachment->path !== null && Storage::disk('public')->exists($attachment->path)) {
                    $out[] = [
                        'name' => (string) $attachment->file_name,
                        'content' => (string) Storage::disk('public')->get($attachment->path),
                        'mime' => (string) ($attachment->mime_type ?? 'application/octet-stream'),
                    ];
                }
            } catch (Throwable) {
                // Missing/unreadable attachment must not block the send.
            }
        }

        return $out;
    }

    /**
     * Hidden preview-text block (F-2.3.2-009). Placed immediately after
     * <body> when there is one, otherwise at the very top of the document —
     * inbox previews read the first visible-order text node.
     */
    private function prependPreheader(string $html, string $preheader): string
    {
        if ($preheader === '') {
            return $html;
        }

        $block = '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">'
            .e($preheader)
            .'</div>';

        if (preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $at).$block.substr($html, $at);
        }

        return $block.$html;
    }

    private function appendFooter(string $html, string $footer): string
    {
        if (trim($footer) === '') {
            return $html;
        }

        $position = stripos($html, '</body>');

        if ($position !== false) {
            return substr($html, 0, $position).$footer.substr($html, $position);
        }

        return $html.$footer;
    }

    private function stringOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
