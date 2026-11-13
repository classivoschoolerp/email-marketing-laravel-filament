<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Jobs\Campaigns\DeliverCampaignWebhookJob;
use App\Models\Campaign;
use Illuminate\Support\Str;
use Throwable;

/**
 * Per-campaign webhooks (spec §2.3.4 F-2.3.4-022).
 *
 * Distinct from the §2.14.2 account-level webhook system on purpose: that one
 * fans an event out to every endpoint a TENANT subscribed, while this is a
 * setting on ONE campaign ("tell my URL when THIS campaign finishes"). Both
 * sign with WebhookSignature and carry the same headers, so a receiver written
 * for one works for the other.
 *
 * The POST itself is queued (docs/ARCHITECTURE.md rule 5) — a campaign's lifecycle must
 * never wait on someone else's HTTP endpoint — and every failure is swallowed:
 * a webhook is an observability side channel, not part of the send.
 */
class CampaignWebhookNotifier
{
    public const EVENT_STARTED = 'campaign.started';

    public const EVENT_PAUSED = 'campaign.paused';

    public const EVENT_RESUMED = 'campaign.resumed';

    public const EVENT_FINISHED = 'campaign.finished';

    public const EVENT_AB_WINNER = 'campaign.ab_winner';

    public const EVENT_FORWARDED = 'campaign.forwarded';

    /** @var list<string> */
    public const EVENTS = [
        self::EVENT_STARTED,
        self::EVENT_PAUSED,
        self::EVENT_RESUMED,
        self::EVENT_FINISHED,
        self::EVENT_AB_WINNER,
        self::EVENT_FORWARDED,
    ];

    /** @return array<string, string> value => label, for the wizard checkboxes */
    public static function options(): array
    {
        $options = [];

        foreach (self::EVENTS as $event) {
            $options[$event] = __('campaigns.webhook_events.'.Str::after($event, 'campaign.'));
        }

        return $options;
    }

    /**
     * Fire one campaign event. Silently does nothing when the campaign has no
     * webhook URL or is not subscribed to this event.
     *
     * @param  array<string, mixed>  $extra
     */
    public function fire(Campaign $campaign, string $event, array $extra = []): bool
    {
        try {
            $url = trim((string) $campaign->webhook_url);

            if ($url === ''
                || ! in_array($event, self::EVENTS, true)
                || ! in_array($event, (array) ($campaign->webhook_events ?? []), true)) {
                return false;
            }

            DeliverCampaignWebhookJob::dispatch(
                (int) $campaign->id,
                $event,
                array_merge([
                    'campaign_uid' => (string) $campaign->uid,
                    'campaign_name' => (string) $campaign->name,
                    'status' => $campaign->status?->value,
                    'delivery' => [
                        'total' => (int) $campaign->delivery_total,
                        'sent' => (int) $campaign->delivery_sent,
                        'failed' => (int) $campaign->delivery_failed,
                    ],
                ], $extra),
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
