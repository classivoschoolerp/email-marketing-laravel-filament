<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Services\Content\MergeTags;
use App\Services\Content\PlainTextGenerator;
use App\Services\Content\Spintax;
use App\Services\SpamCheck\SpamCheckerInterface;
use App\Services\SpamCheck\SpamCheckResult;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Pre-send spam score with rule breakdown (spec §2.3.5 F-2.3.5-004).
 *
 * Reuses the D-08 SpamCheckerInterface that the send path already gates on,
 * so the number the customer sees in step 4 is produced by exactly the same
 * checker that can later refuse the send (D-49) — a preview that disagreed
 * with the gate would be worse than none.
 *
 * The message is built with SAMPLE merge-tag data (no subscriber exists yet)
 * through the same MimeBuilder the drivers use, so headers, MIME structure and
 * the body all match a real send closely enough for SpamAssassin's rules.
 *
 * When no checker is configured the result carries `checked = false` and the
 * UI says so (D-08) rather than displaying a fabricated 0.0.
 */
class CampaignSpamScore
{
    public function __construct(
        private readonly SpamCheckerInterface $checker,
        private readonly MergeTags $mergeTags,
        private readonly Spintax $spintax,
        private readonly PlainTextGenerator $plainText,
    ) {}

    public function check(Campaign $campaign): SpamCheckResult
    {
        return $this->checker->check($this->rawMessage($campaign));
    }

    /** The RFC 2822 message the checker is asked about. */
    public function rawMessage(Campaign $campaign): string
    {
        $list = $campaign->email_list_id !== null
            ? $campaign->list()->withoutGlobalScopes()->first()
            : null;

        $values = $this->mergeTags->sampleValues($campaign, $list);
        $seed = 'spam-check:'.$campaign->uid;

        $render = fn (string $content): string => $this->spintax->render(
            $this->mergeTags->render($content, $values, $seed),
            $seed,
        );

        $html = $render((string) $campaign->content_html);

        $email = (new Email)
            ->from(new Address(
                $this->address((string) $campaign->from_email),
                $campaign->from_name === null ? '' : $render((string) $campaign->from_name),
            ))
            ->to(new Address($this->address((string) ($values['EMAIL'] ?? ''))))
            ->subject($render((string) $campaign->subject))
            ->text($this->plainText->generate($html));

        if (! $campaign->plain_text_only) {
            $email->html($html);
        }

        $replyTo = trim((string) $campaign->reply_to);

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
            $email->replyTo(new Address($replyTo));
        }

        // Built directly rather than through MimeBuilder: no delivery server is
        // picked at pre-flight, and MimeBuilder's job is server-specific
        // (DKIM signing, envelope/from overrides) — none of which exists yet.
        return $email->toString();
    }

    /** A syntactically valid address for the preview message. */
    private function address(string $candidate): string
    {
        $candidate = trim($candidate);

        return $candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false
            ? $candidate
            : 'preview@example.com';
    }
}
