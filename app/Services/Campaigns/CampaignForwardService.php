<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Contracts\CampaignWebViewSource;
use App\Mail\CampaignForwardMail;
use App\Models\Campaign;
use App\Models\CampaignForward;
use App\Models\Subscriber;
use App\Support\Privacy\IpAnonymizer;
use App\Support\Stats\LiveCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Forward-to-a-friend (spec §1.6 F-1.6-002) and its tracker (§1.7 F-1.7-004).
 *
 * One row per (forwarder, recipient) in `campaign_forwards`; the mail itself
 * is QUEUED (docs/ARCHITECTURE.md rule 5 — nothing bulk or network-bound runs in a
 * request). The forwarded body is the campaign's public WEB VIEW, so no
 * tracking pixel, personal unsubscribe link or merge-tag value belonging to
 * the forwarder ever leaves with it.
 *
 * Anti-abuse, because this endpoint takes an arbitrary address from an
 * unauthenticated visitor:
 *   - {@see MAX_RECIPIENTS} addresses per submission;
 *   - the subject and body are the CAMPAIGN's, never the visitor's — only a
 *     short plain-text note is carried through, and it is escaped;
 *   - {@see alreadyForwarded()} makes a (campaign, forwarder, recipient) pair
 *     one-shot, so the form cannot be used to mail-bomb one address;
 *   - captcha + honeypot + route throttle live in the controller.
 */
class CampaignForwardService
{
    public const MAX_RECIPIENTS = 5;

    public function __construct(
        private readonly CampaignWebViewSource $webView,
        private readonly LiveCounter $counter,
    ) {}

    /** The campaign behind a public forward URL, or null. */
    public function campaign(string $campaignUid): ?Campaign
    {
        if ($campaignUid === '') {
            return null;
        }

        return Campaign::withoutTenancy()->where('uid', $campaignUid)->first();
    }

    /** The forwarding subscriber, or null when the uid is unknown. */
    public function subscriber(string $subscriberUid): ?Subscriber
    {
        if ($subscriberUid === '') {
            return null;
        }

        return Subscriber::withoutTenancy()->where('uid', $subscriberUid)->first();
    }

    /**
     * Record + queue the forwards. Returns the rows actually created (an
     * address already forwarded for this pair is silently skipped).
     *
     * @param  list<string>  $recipients
     * @return list<CampaignForward>
     */
    public function forward(
        Campaign $campaign,
        ?Subscriber $subscriber,
        array $recipients,
        ?string $note = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        $html = $this->webView->webViewHtml((string) $campaign->uid);

        if ($html === null) {
            return [];
        }

        $note = $this->cleanNote($note);
        $created = [];

        foreach (array_slice($this->normalizeAddresses($recipients), 0, self::MAX_RECIPIENTS) as $address) {
            if ($this->alreadyForwarded($campaign, $subscriber, $address)) {
                continue;
            }

            $forward = CampaignForward::query()->create([
                'campaign_id' => $campaign->id,
                'campaign_uid' => $campaign->uid,
                'subscriber_uid' => $subscriber?->uid,
                'recipient_email' => $address,
                'status' => CampaignForward::STATUS_PENDING,
                'personal_note' => $note,
                'ip' => IpAnonymizer::maybe((string) $ip),
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
                'created_at' => Carbon::now(),
            ]);

            $this->deliver($campaign, $forward, $html, $note);

            $created[] = $forward;
        }

        if ($created !== []) {
            $this->counter->increment('track:forwards');
            $this->counter->increment('campaigns:'.$campaign->uid.':forwards');

            app(CampaignWebhookNotifier::class)->fire($campaign, CampaignWebhookNotifier::EVENT_FORWARDED, [
                'subscriber_uid' => $subscriber?->uid,
                'recipients' => count($created),
            ]);
        }

        return $created;
    }

    /** Total forwards recorded for a campaign (F-2.3.8 header stat). */
    public function countFor(Campaign $campaign): int
    {
        return (int) CampaignForward::query()->where('campaign_uid', $campaign->uid)->count();
    }

    /** Distinct subscribers who forwarded this campaign at least once. */
    public function forwarderCountFor(Campaign $campaign): int
    {
        return (int) CampaignForward::query()
            ->where('campaign_uid', $campaign->uid)
            ->whereNotNull('subscriber_uid')
            ->distinct()
            ->count('subscriber_uid');
    }

    /**
     * The public forward URL for the {FORWARD_FRIEND_URL} merge tag.
     * Without a subscriber there is nobody to attribute the forward to, so
     * the tag stays neutral rather than emitting an unattributable link.
     */
    public function urlFor(Campaign $campaign, ?Subscriber $subscriber): ?string
    {
        if ($subscriber === null) {
            return null;
        }

        return route('campaigns.track.forward', [
            'campaign_uid' => $campaign->uid,
            'subscriber_uid' => $subscriber->uid,
        ]);
    }

    private function deliver(Campaign $campaign, CampaignForward $forward, string $html, ?string $note): void
    {
        try {
            Mail::to($forward->recipient_email)->queue(new CampaignForwardMail(
                (string) ($campaign->subject ?: $campaign->name),
                $this->wrap($html, $note),
            ));

            $forward->forceFill([
                'status' => CampaignForward::STATUS_SENT,
                'sent_at' => Carbon::now(),
            ])->save();
        } catch (Throwable $e) {
            $forward->forceFill([
                'status' => CampaignForward::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();
        }
    }

    /** Prepend the (escaped) personal note above the campaign body. */
    private function wrap(string $html, ?string $note): string
    {
        if ($note === null || $note === '') {
            return $html;
        }

        $block = '<div style="margin:0 0 24px;padding:16px;border-left:4px solid #e5e7eb;color:#374151;">'
            .nl2br(e($note))
            .'</div>';

        if (preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $at).$block.substr($html, $at);
        }

        return $block.$html;
    }

    private function alreadyForwarded(Campaign $campaign, ?Subscriber $subscriber, string $address): bool
    {
        return CampaignForward::query()
            ->where('campaign_uid', $campaign->uid)
            ->where('recipient_email', $address)
            ->when(
                $subscriber !== null,
                fn ($query) => $query->where('subscriber_uid', $subscriber->uid),
                fn ($query) => $query->whereNull('subscriber_uid'),
            )
            ->exists();
    }

    /**
     * @param  list<string>  $recipients
     * @return list<string>
     */
    private function normalizeAddresses(array $recipients): array
    {
        $out = [];

        foreach ($recipients as $address) {
            $address = mb_strtolower(trim((string) $address));

            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $out[$address] = $address;
            }
        }

        return array_values($out);
    }

    private function cleanNote(?string $note): ?string
    {
        $note = trim((string) $note);

        if ($note === '') {
            return null;
        }

        // Control characters out, newlines kept (they become <br> on render).
        $note = (string) preg_replace('/[^\P{C}\n]/u', '', $note);

        return mb_substr($note, 0, 500);
    }
}
