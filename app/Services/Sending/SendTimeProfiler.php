<?php

declare(strict_types=1);

namespace App\Services\Sending;

use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\Subscriber;
use App\Models\SubscriberSendTimeProfile;
use App\Services\Reports\CampaignComparisonService;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Send Time Optimization — the LEARNING half (spec §2.3.4 F-2.3.4-011, WP-46).
 *
 * Builds, per subscriber, a day-of-week × hour engagement histogram from that
 * subscriber's OWN opens and clicks and stores the hour it points at.
 *
 * ================== "AI-PREDICTED", PRECISELY ==================
 * The spec calls this AI-predicted. It is prediction LEARNED FROM DATA — a
 * weighted histogram over the tenant's own tracking tables — and deliberately
 * NOT a language model. Asking a model "when should I email Ana?" would put an
 * invented hour on a real send, which is exactly what D-105 forbids for
 * SubjectLinePredictor. Every number below is reproducible from
 * campaign_track_opens / campaign_track_clicks by anyone with the same rows.
 *
 * ========================= THE WEIGHTS =========================
 * Each engagement event contributes  base × recency  to one (day, hour) cell.
 *
 *   BASE, by event type
 *     open   {@see WEIGHT_OPEN}  = 1.0
 *     click  {@see WEIGHT_CLICK} = 3.0
 *   A click is a deliberate act performed by a person. An open can be fired by
 *   an image proxy (Apple MPP, Gmail) at a moment the recipient was nowhere
 *   near their inbox, so an open is worth a third of a click rather than the
 *   same. The ratio is a stated editorial choice, not a fitted parameter — it
 *   is a constant so it can be changed and re-run.
 *
 *   RECENCY, exponential decay with a {@see HALF_LIFE_DAYS} = 90-day half-life
 *     recency = 0.5 ^ (ageDays / 90)
 *   so an event today counts 1.0, one 90 days old counts 0.5, one a year old
 *   counts 0.5^(365/90) = 0.061. That is what "an open last month beats one
 *   from a year ago" means numerically. Events older than
 *   {@see LOOKBACK_DAYS} = 365 are dropped entirely rather than decayed to a
 *   rounding error, which keeps the query bounded.
 *
 *   MINIMUM EVIDENCE {@see MIN_EVENTS} = 3 raw events. Below three events the
 *   modal hour is the hour of a single open — noise wearing a prediction's
 *   clothes. Such a subscriber gets `source = none` and the send path leaves
 *   their campaign time alone.
 *
 * ======================== THE TIMEZONE =========================
 * Tracking rows carry `created_at` in UTC and no timezone column at all. The
 * histogram is bucketed in the SUBSCRIBER'S OWN timezone when
 * `subscribers.timezone` is set, and in UTC when it is not — never guessed
 * from an IP, exactly as RecipientLocalTime already refuses to guess. Note
 * that the choice does not move anyone's mail: a UTC histogram of the same
 * events picks the same absolute moment; the timezone only changes the label
 * the UI shows and the clock the delay is computed against.
 *
 * ===================== THE FALLBACK CHAIN ======================
 *   1. the subscriber's own history (≥ MIN_EVENTS)     → source `subscriber`
 *   2. the tenant's account-level best hour, read from
 *      CampaignComparisonService::sendTimeAnalysis()   → source `account`
 *   3. nothing                                          → source `none`,
 *      and the campaign's configured send time is used unchanged.
 * No step ever invents an hour.
 *
 * Refreshing is QUEUED (RefreshSendTimeProfilesJob / `send-time:profile`);
 * this class is never called from the send path.
 */
final class SendTimeProfiler
{
    public const WEIGHT_OPEN = 1.0;

    public const WEIGHT_CLICK = 3.0;

    public const HALF_LIFE_DAYS = 90.0;

    public const LOOKBACK_DAYS = 365;

    public const MIN_EVENTS = 3;

    /** A profile older than this is stale and worth recomputing. */
    public const STALE_HOURS = 168;

    public function __construct(private readonly CampaignComparisonService $comparison) {}

    /**
     * Recompute every profile for one tenant (optionally one list).
     * Returns the number of profiles written.
     */
    public function refreshCustomer(int $customerId, ?int $listId = null, int $chunkSize = 500): int
    {
        $accountHour = $this->accountBestHour($customerId);
        $written = 0;

        $query = Subscriber::withoutTenancy()->where('customer_id', $customerId);

        if ($listId !== null) {
            $query->where('list_id', $listId);
        }

        $query->orderBy('id')->chunkById($chunkSize, function ($subscribers) use ($customerId, $accountHour, &$written): void {
            $uids = $subscribers->pluck('uid')->map(strval(...))->all();
            $events = $this->eventsFor($uids);

            foreach ($subscribers as $subscriber) {
                $this->write(
                    $subscriber,
                    $events[(string) $subscriber->uid] ?? [],
                    $accountHour,
                    $customerId,
                );
                $written++;
            }
        });

        return $written;
    }

    /** Recompute exactly one subscriber's profile. */
    public function refreshSubscriber(Subscriber $subscriber): SubscriberSendTimeProfile
    {
        $customerId = (int) $subscriber->customer_id;

        return $this->write(
            $subscriber,
            $this->eventsFor([(string) $subscriber->uid])[(string) $subscriber->uid] ?? [],
            $this->accountBestHour($customerId),
            $customerId,
        );
    }

    /**
     * Weighted day-of-week × hour histogram. PURE — no database, no clock of
     * its own — so the weights above are directly testable.
     *
     * @param  list<array{at: DateTimeInterface, weight: float}>  $events
     * @return array{
     *     hours: array<int, float>, days: array<int, float>,
     *     best_hour: ?int, best_day: ?int,
     *     confidence: float, weight_total: float, events: int
     * }
     */
    public function histogram(array $events, string $zone, DateTimeInterface $now): array
    {
        $hours = [];
        $days = [];
        $total = 0.0;
        $counted = 0;
        $reference = Carbon::instance(Carbon::parse($now));

        foreach ($events as $event) {
            $at = Carbon::parse($event['at']);
            $ageDays = ($reference->getTimestamp() - $at->getTimestamp()) / 86400;

            if ($ageDays < 0 || $ageDays > self::LOOKBACK_DAYS) {
                continue;
            }

            $weight = (float) $event['weight'] * (0.5 ** ($ageDays / self::HALF_LIFE_DAYS));

            $local = $this->inZone($at, $zone);
            $hour = (int) $local->format('G');
            $day = (int) $local->format('w');

            $hours[$hour] = ($hours[$hour] ?? 0.0) + $weight;
            $days[$day] = ($days[$day] ?? 0.0) + $weight;
            $total += $weight;
            $counted++;
        }

        return [
            'hours' => $hours,
            'days' => $days,
            'best_hour' => $this->peak($hours),
            'best_day' => $this->peak($days),
            'confidence' => $total > 0.0 ? ($hours[$this->peak($hours) ?? -1] ?? 0.0) / $total : 0.0,
            'weight_total' => $total,
            'events' => $counted,
        ];
    }

    /**
     * The tenant's account-level best hour (fallback 2), read from the
     * READ-ONLY reports service. Null when the tenant has no sent campaign
     * with delivered mail — an empty bucket reports null, never 0 (the
     * sendTimeAnalysis() contract).
     */
    public function accountBestHour(int $customerId): ?int
    {
        try {
            $hour = $this->comparison->sendTimeAnalysis($customerId)['best_hour'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return $hour === null ? null : (int) $hour;
    }

    /**
     * Engagement events per subscriber uid, newest first, within the lookback
     * window. Two indexed reads (opens, clicks) rather than one per
     * subscriber; `campaign_track_*` is keyed by subscriber_uid with an index
     * on (campaign_uid, subscriber_uid).
     *
     * @param  list<string>  $uids
     * @return array<string, list<array{at: string, weight: float}>>
     */
    public function eventsFor(array $uids): array
    {
        $uids = array_values(array_filter(array_unique($uids), static fn (string $uid): bool => $uid !== ''));

        if ($uids === []) {
            return [];
        }

        $since = Carbon::now()->subDays(self::LOOKBACK_DAYS);
        $events = [];

        foreach ([
            [CampaignTrackOpen::query()->getModel()->getTable(), self::WEIGHT_OPEN],
            [CampaignTrackClick::query()->getModel()->getTable(), self::WEIGHT_CLICK],
        ] as [$table, $weight]) {
            foreach (array_chunk($uids, 500) as $chunk) {
                $rows = DB::table($table)
                    ->select(['subscriber_uid', 'created_at'])
                    ->whereIn('subscriber_uid', $chunk)
                    ->where('created_at', '>=', $since)
                    ->get();

                foreach ($rows as $row) {
                    $events[(string) $row->subscriber_uid][] = [
                        'at' => (string) $row->created_at,
                        'weight' => $weight,
                    ];
                }
            }
        }

        return $events;
    }

    /* ---------------------------------------------------------------- */

    /**
     * @param  list<array{at: string, weight: float}>  $events
     */
    private function write(Subscriber $subscriber, array $events, ?int $accountHour, int $customerId): SubscriberSendTimeProfile
    {
        $zone = $this->zoneFor($subscriber);
        $now = Carbon::now();

        $histogram = $this->histogram(
            array_map(
                static fn (array $event): array => ['at' => Carbon::parse($event['at']), 'weight' => $event['weight']],
                $events,
            ),
            $zone ?? 'UTC',
            $now,
        );

        $ownHistory = $histogram['events'] >= self::MIN_EVENTS && $histogram['best_hour'] !== null;

        [$source, $bestHour, $bestDay] = match (true) {
            $ownHistory => [SubscriberSendTimeProfile::SOURCE_SUBSCRIBER, $histogram['best_hour'], $histogram['best_day']],
            $accountHour !== null => [SubscriberSendTimeProfile::SOURCE_ACCOUNT, $accountHour, null],
            default => [SubscriberSendTimeProfile::SOURCE_NONE, null, null],
        };

        $attributes = [
            'customer_id' => $customerId,
            'list_id' => $subscriber->list_id,
            // The account-level hour is expressed in the APPLICATION timezone
            // (it comes from campaign start times), not the subscriber's — so
            // it must not be labelled with the subscriber's zone.
            'timezone' => $source === SubscriberSendTimeProfile::SOURCE_SUBSCRIBER ? $zone : null,
            'best_hour' => $bestHour,
            'best_day' => $bestDay,
            'confidence' => $ownHistory ? round($histogram['confidence'], 6) : 0.0,
            'events' => $histogram['events'],
            'weight_total' => round($histogram['weight_total'], 6),
            'histogram' => ['hours' => $histogram['hours'], 'days' => $histogram['days']],
            'source' => $source,
            'computed_at' => $now,
        ];

        $profile = SubscriberSendTimeProfile::withoutTenancy()
            ->where('subscriber_id', $subscriber->id)
            ->first();

        if ($profile === null) {
            $profile = new SubscriberSendTimeProfile;
            $profile->subscriber_id = $subscriber->id;
        }

        $profile->forceFill($attributes)->save();

        return $profile;
    }

    /** The subscriber's recorded timezone, or null — never guessed. */
    private function zoneFor(Subscriber $subscriber): ?string
    {
        $zone = trim((string) $subscriber->timezone);

        if ($zone === '' || ! in_array($zone, timezone_identifiers_list(), true)) {
            return null;
        }

        return $zone;
    }

    private function inZone(Carbon $at, string $zone): Carbon
    {
        try {
            return $at->copy()->setTimezone($zone);
        } catch (Throwable) {
            return $at->copy()->setTimezone('UTC');
        }
    }

    /**
     * Heaviest bucket; ties break to the LOWEST key so the answer is total
     * and reproducible across runs and database engines.
     *
     * @param  array<int, float>  $buckets
     */
    private function peak(array $buckets): ?int
    {
        if ($buckets === []) {
            return null;
        }

        $bestKey = null;
        $bestValue = -1.0;

        foreach ($buckets as $key => $value) {
            if ($value > $bestValue + 1e-12 || ($bestKey !== null && abs($value - $bestValue) <= 1e-12 && $key < $bestKey)) {
                $bestKey = (int) $key;
                $bestValue = (float) $value;
            }
        }

        return $bestKey;
    }
}
