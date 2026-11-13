<?php

declare(strict_types=1);

namespace App\Sending\Pipeline;

use App\Models\Campaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Send at recipient's local time" (spec §2.3.4 F-2.3.4-010, WP-35).
 *
 * The campaign's scheduled moment is read as a LOCAL TIME OF DAY in the
 * campaign timezone (09:00 for a campaign scheduled 09:00 Europe/Paris), and
 * every recipient's job is delayed until the next occurrence of that clock
 * time in THEIR timezone. Delays are capped at 24 hours — the clock time
 * always comes round within a day, so a larger delay could only be a bug.
 *
 * Timezone resolution, in order, and never guessed:
 *   1. `subscribers.timezone`;
 *   2. the value of a TIMEZONE custom field on the subscriber's list;
 *   3. unknown → no delay at all. A subscriber whose timezone nobody recorded
 *      is sent at the campaign's own time; inventing one (from the IP, from
 *      the list default) would be fabricating data on a per-person basis.
 *
 * This is NOT Send Time Optimization: there is no prediction here, only a
 * timezone shift of a time the customer chose. Prediction is F-2.3.4-011,
 * shipped by WP-46 as {@see SendTimeOptimizer} — that class's docblock fixes
 * which of the two wins, per recipient, when a campaign enables both.
 */
final class RecipientLocalTime
{
    /** Custom-field tag a list may use to carry the subscriber timezone. */
    public const FIELD_TAG = 'TIMEZONE';

    private const MAX_DELAY_SECONDS = 86400;

    public function enabled(Campaign $campaign): bool
    {
        return (bool) $campaign->send_at_recipient_local_time;
    }

    /**
     * Per-recipient delay in seconds.
     *
     * @param  Collection<int, object>  $rows  objects exposing `id`
     * @return array<int, int> subscriber id => delay seconds
     */
    public function delaysFor(Campaign $campaign, Collection $rows): array
    {
        if (! $this->enabled($campaign) || $rows->isEmpty()) {
            return [];
        }

        $target = $this->targetClockTime($campaign);
        $zones = $this->timezonesFor($rows->pluck('id')->map(intval(...))->all());
        $now = Carbon::now();
        $delays = [];

        foreach ($zones as $subscriberId => $zone) {
            $delay = $this->delayFor($now, $target, (string) $zone);

            if ($delay > 0) {
                $delays[(int) $subscriberId] = $delay;
            }
        }

        return $delays;
    }

    /** Seconds from `$now` until `$clock` (H:i:s) next occurs in `$zone`. */
    public function delayFor(Carbon $now, string $clock, string $zone): int
    {
        try {
            $local = $now->copy()->setTimezone($zone);
        } catch (Throwable) {
            return 0;
        }

        [$hour, $minute, $second] = array_pad(array_map(intval(...), explode(':', $clock)), 3, 0);

        $target = $local->copy()->setTime($hour, $minute, $second);

        if ($target->lessThanOrEqualTo($local)) {
            $target->addDay();
        }

        return (int) min(self::MAX_DELAY_SECONDS, max(0, $target->getTimestamp() - $now->getTimestamp()));
    }

    /** The campaign's intended local clock time, "H:i:s" in its own timezone. */
    public function targetClockTime(Campaign $campaign): string
    {
        $zone = (string) ($campaign->timezone ?? config('app.timezone', 'UTC'));
        $moment = $campaign->send_at ?? $campaign->send_started_at ?? Carbon::now();

        try {
            return Carbon::parse($moment)->setTimezone($zone)->format('H:i:s');
        } catch (Throwable) {
            return Carbon::parse($moment)->format('H:i:s');
        }
    }

    /**
     * @param  list<int>  $subscriberIds
     * @return array<int, string> subscriber id => valid timezone identifier
     */
    private function timezonesFor(array $subscriberIds): array
    {
        if ($subscriberIds === []) {
            return [];
        }

        $zones = [];

        foreach (DB::table('subscribers')->whereIn('id', $subscriberIds)
            ->whereNotNull('timezone')->pluck('timezone', 'id') as $id => $zone) {
            if ($this->isValidZone((string) $zone)) {
                $zones[(int) $id] = (string) $zone;
            }
        }

        $missing = array_values(array_diff($subscriberIds, array_keys($zones)));

        if ($missing === []) {
            return $zones;
        }

        $rows = DB::table('subscriber_field_values')
            ->join('list_fields', 'list_fields.id', '=', 'subscriber_field_values.list_field_id')
            ->whereIn('subscriber_field_values.subscriber_id', $missing)
            ->where('list_fields.tag', self::FIELD_TAG)
            ->pluck('subscriber_field_values.value', 'subscriber_field_values.subscriber_id');

        foreach ($rows as $id => $value) {
            if ($this->isValidZone((string) $value)) {
                $zones[(int) $id] = (string) $value;
            }
        }

        return $zones;
    }

    private function isValidZone(string $zone): bool
    {
        $zone = trim($zone);

        return $zone !== '' && in_array($zone, timezone_identifiers_list(), true);
    }
}
