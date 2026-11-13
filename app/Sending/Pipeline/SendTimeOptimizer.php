<?php

declare(strict_types=1);

namespace App\Sending\Pipeline;

use App\Models\Campaign;
use App\Models\SubscriberSendTimeProfile;
use App\Services\Sending\SendTimeProfiler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Send Time Optimization — the SEND-PATH half (spec §2.3.4 F-2.3.4-011, WP-46).
 *
 * Reads the profiles App\Services\Sending\SendTimeProfiler learned (that class
 * documents every weight) and turns them into the same per-recipient delay map
 * {@see RecipientLocalTime} already produces, so StartCampaignSendJob gained
 * one line and no new concept. NOTHING is computed here: a profile that does
 * not exist is a profile that says nothing, and the recipient is mailed at the
 * campaign's own time.
 *
 * ============ WHICH SETTING WINS WHEN BOTH ARE ON ============
 * `send_at_recipient_local_time` (F-2.3.4-010) shifts the customer's CHOSEN
 * clock time into the recipient's timezone. `send_time_optimized`
 * (F-2.3.4-011) REPLACES that clock time with one learned from the recipient's
 * own behaviour — a learned hour already encodes where and when that person
 * reads mail, so applying both would shift an already-local hour a second
 * time. The rule, per RECIPIENT:
 *
 *   - the optimizer has a prediction for this person  → the optimizer wins;
 *   - it has none (no profile, or `source = none`)    → the local-time shift
 *     applies unchanged.
 *
 * So turning the optimizer on never silently discards the other setting: it
 * takes over only where it actually has something to say. StartCampaignSendJob
 * implements this by preferring the optimizer's map and falling through to the
 * local-time map key by key.
 *
 * DELAYS are capped at {@see MAX_DELAY_SECONDS} (24 h) for the same reason
 * RecipientLocalTime caps them: an hour of the day comes round within a day,
 * so a longer delay could only be a bug.
 */
final class SendTimeOptimizer
{
    private const MAX_DELAY_SECONDS = 86400;

    public function enabled(Campaign $campaign): bool
    {
        return (bool) $campaign->send_time_optimized;
    }

    /**
     * Per-recipient delay in seconds. Keys are subscriber ids; a recipient
     * with no usable prediction is simply absent from the map.
     *
     * @param  Collection<int, object>  $rows  objects exposing `id`
     * @return array<int, int>
     */
    public function delaysFor(Campaign $campaign, Collection $rows): array
    {
        if (! $this->enabled($campaign) || $rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->map(intval(...))->all();
        $now = Carbon::now();
        $campaignZone = $this->campaignZone($campaign);
        $delays = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            $profiles = SubscriberSendTimeProfile::withoutTenancy()
                ->where('customer_id', $campaign->customer_id)
                ->whereIn('subscriber_id', $chunk)
                ->get(['subscriber_id', 'best_hour', 'timezone', 'source']);

            foreach ($profiles as $profile) {
                if (! $profile->predicts()) {
                    continue;
                }

                // A `subscriber` profile carries the person's own timezone; an
                // `account` profile's hour comes from campaign start times and
                // therefore belongs to the campaign/app timezone.
                $zone = $profile->source === SubscriberSendTimeProfile::SOURCE_SUBSCRIBER
                    ? $profile->zone()
                    : $campaignZone;

                $delay = $this->delayFor($now, (int) $profile->best_hour, $zone);

                if ($delay > 0) {
                    $delays[(int) $profile->subscriber_id] = $delay;
                }
            }
        }

        return $delays;
    }

    /** Seconds from `$now` until `$hour`:00:00 next occurs in `$zone`. */
    public function delayFor(Carbon $now, int $hour, string $zone): int
    {
        $hour = max(0, min(23, $hour));

        try {
            $local = $now->copy()->setTimezone($zone);
        } catch (Throwable) {
            return 0;
        }

        $target = $local->copy()->setTime($hour, 0, 0);

        if ($target->lessThanOrEqualTo($local)) {
            $target->addDay();
        }

        return (int) min(self::MAX_DELAY_SECONDS, max(0, $target->getTimestamp() - $now->getTimestamp()));
    }

    /**
     * Coverage read-out for the campaign UI: how many of the campaign's
     * recipients the optimizer can actually speak for, and the predicted
     * hour distribution. Empty rather than invented when nothing is known.
     *
     * @return array{
     *     total: int, subscriber: int, account: int, none: int,
     *     hours: array<int, int>, computed_at: ?string, stale: bool
     * }
     */
    public function coverage(Campaign $campaign): array
    {
        $summary = [
            'total' => 0,
            SubscriberSendTimeProfile::SOURCE_SUBSCRIBER => 0,
            SubscriberSendTimeProfile::SOURCE_ACCOUNT => 0,
            SubscriberSendTimeProfile::SOURCE_NONE => 0,
            'hours' => [],
            'computed_at' => null,
            'stale' => true,
        ];

        if ($campaign->email_list_id === null) {
            return $summary;
        }

        $profiles = SubscriberSendTimeProfile::withoutTenancy()
            ->where('customer_id', $campaign->customer_id)
            ->where('list_id', $campaign->email_list_id)
            ->get(['best_hour', 'source', 'computed_at']);

        $latest = null;

        foreach ($profiles as $profile) {
            $summary['total']++;
            $source = (string) $profile->source;
            $summary[$source] = ($summary[$source] ?? 0) + 1;

            if ($profile->best_hour !== null) {
                $hour = (int) $profile->best_hour;
                $summary['hours'][$hour] = ($summary['hours'][$hour] ?? 0) + 1;
            }

            if ($profile->computed_at !== null && ($latest === null || $profile->computed_at->greaterThan($latest))) {
                $latest = $profile->computed_at;
            }
        }

        ksort($summary['hours']);

        $summary['computed_at'] = $latest?->toDateTimeString();
        $summary['stale'] = $latest === null
            || $latest->lessThan(Carbon::now()->subHours(SendTimeProfiler::STALE_HOURS));

        return $summary;
    }

    private function campaignZone(Campaign $campaign): string
    {
        $zone = trim((string) ($campaign->timezone ?? ''));

        if ($zone !== '' && in_array($zone, timezone_identifiers_list(), true)) {
            return $zone;
        }

        return (string) config('app.timezone', 'UTC');
    }
}
