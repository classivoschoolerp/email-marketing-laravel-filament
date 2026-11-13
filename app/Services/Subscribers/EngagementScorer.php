<?php

declare(strict_types=1);

namespace App\Services\Subscribers;

use App\Models\CampaignBounceLog;
use App\Models\CampaignComplaintLog;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUnsubscribe;
use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Engagement score / rating (spec §2.2.5 F-2.2.5-006).
 *
 * A deterministic, fully documented function of the tracking tables — no
 * stored column, no model, no magic. Same inputs, same number, always; the
 * unit test pins exact values.
 *
 * ── The formula ────────────────────────────────────────────────────────────
 * Only events inside the last WINDOW_DAYS (365) count. Each open/click is
 * weighted by how recent it is, because "opened twice last week" and "opened
 * twice eleven months ago" are not the same person:
 *
 *      age ≤  30 days → 1.0
 *      age ≤  90 days → 0.6
 *      age ≤ 180 days → 0.3
 *      age ≤ 365 days → 0.1
 *      older          → dropped
 *
 *      points = 2 × weighted opens
 *             + 5 × weighted clicks          (a click is intent, not curiosity)
 *             − 10 × bounces                 (in-window)
 *             − 25 × complaints              (in-window)
 *             − 20 × campaign unsubscribes   (in-window)
 *
 *      score  = clamp(round(points), 0, 100)
 *
 * Weights: a click is worth 2.5 opens; one spam complaint wipes out roughly
 * twelve fresh opens; one unsubscribe wipes out ten. The cap at 100 means the
 * score is a rating, not a leaderboard — a subscriber who opens everything is
 * "high" and cannot climb further.
 *
 * ── The rating ─────────────────────────────────────────────────────────────
 *      0        → inactive     (nothing measurable, or fully cancelled out)
 *      1 –  29  → low
 *     30 –  69  → medium
 *     70 – 100  → high
 *
 * Tenancy follows SubscriberActivityTimeline exactly: uid-keyed tracking
 * tables are fenced to the tenant's campaigns (TrackingScope), id-keyed logs
 * are fenced by the subscriber key itself.
 */
final class EngagementScorer
{
    public const WINDOW_DAYS = 365;

    public const RATING_INACTIVE = 'inactive';

    public const RATING_LOW = 'low';

    public const RATING_MEDIUM = 'medium';

    public const RATING_HIGH = 'high';

    public const POINTS_PER_OPEN = 2.0;

    public const POINTS_PER_CLICK = 5.0;

    public const PENALTY_PER_BOUNCE = 10.0;

    public const PENALTY_PER_COMPLAINT = 25.0;

    public const PENALTY_PER_UNSUBSCRIBE = 20.0;

    /** [max age in days, weight] — evaluated in order, first match wins. */
    private const DECAY = [
        [30, 1.0],
        [90, 0.6],
        [180, 0.3],
        [self::WINDOW_DAYS, 0.1],
    ];

    /**
     * @return array{
     *     score: int,
     *     rating: string,
     *     points: float,
     *     opens: int,
     *     clicks: int,
     *     bounces: int,
     *     complaints: int,
     *     unsubscribes: int,
     *     weighted_opens: float,
     *     weighted_clicks: float,
     * }
     */
    public function score(Subscriber $subscriber, ?Carbon $now = null): array
    {
        $now = $now?->copy() ?? Carbon::now();
        $customerId = (int) $subscriber->customer_id;
        $since = $now->copy()->subDays(self::WINDOW_DAYS);

        $openBuckets = $this->bucketedUidCounts(CampaignTrackOpen::query(), $subscriber, $customerId, $now);
        $clickBuckets = $this->bucketedUidCounts(CampaignTrackClick::query(), $subscriber, $customerId, $now);

        $weightedOpens = $this->weigh($openBuckets);
        $weightedClicks = $this->weigh($clickBuckets);

        $bounces = CampaignBounceLog::query()
            ->where('subscriber_id', $subscriber->id)
            ->where('created_at', '>=', $since)
            ->count();

        $complaints = CampaignComplaintLog::query()
            ->where('subscriber_id', $subscriber->id)
            ->where('created_at', '>=', $since)
            ->count();

        $unsubscribes = CampaignTrackUnsubscribe::query()
            ->where('subscriber_uid', $subscriber->uid)
            ->whereIn('campaign_uid', TrackingScope::tenantCampaignUids($customerId))
            ->where('created_at', '>=', $since)
            ->count();

        $points = self::POINTS_PER_OPEN * $weightedOpens
            + self::POINTS_PER_CLICK * $weightedClicks
            - self::PENALTY_PER_BOUNCE * $bounces
            - self::PENALTY_PER_COMPLAINT * $complaints
            - self::PENALTY_PER_UNSUBSCRIBE * $unsubscribes;

        $score = (int) max(0, min(100, (int) round($points)));

        return [
            'score' => $score,
            'rating' => self::ratingFor($score),
            'points' => round($points, 2),
            'opens' => array_sum($openBuckets),
            'clicks' => array_sum($clickBuckets),
            'bounces' => $bounces,
            'complaints' => $complaints,
            'unsubscribes' => $unsubscribes,
            'weighted_opens' => round($weightedOpens, 2),
            'weighted_clicks' => round($weightedClicks, 2),
        ];
    }

    public static function ratingFor(int $score): string
    {
        return match (true) {
            $score <= 0 => self::RATING_INACTIVE,
            $score < 30 => self::RATING_LOW,
            $score < 70 => self::RATING_MEDIUM,
            default => self::RATING_HIGH,
        };
    }

    public static function ratingLabel(string $rating): string
    {
        return __('subscribers.engagement_score.rating.'.$rating);
    }

    public static function ratingColor(string $rating): string
    {
        return match ($rating) {
            self::RATING_HIGH => 'success',
            self::RATING_MEDIUM => 'warning',
            self::RATING_LOW => 'danger',
            default => 'gray',
        };
    }

    /**
     * Counts per decay bucket, tenant-fenced. One query per bucket: a profile
     * read of an indexed (subscriber_uid, created_at) range, four times.
     *
     * @return list<int> aligned with self::DECAY
     */
    private function bucketedUidCounts(Builder $query, Subscriber $subscriber, int $customerId, Carbon $now): array
    {
        $counts = [];
        $previous = 0;

        foreach (self::DECAY as [$days, $weight]) {
            $counts[] = (clone $query)
                ->where('subscriber_uid', $subscriber->uid)
                ->whereIn('campaign_uid', TrackingScope::tenantCampaignUids($customerId))
                ->where('created_at', '>=', $now->copy()->subDays($days))
                ->when($previous > 0, fn (Builder $q): Builder => $q->where('created_at', '<', $now->copy()->subDays($previous)))
                ->count();

            $previous = $days;
        }

        return $counts;
    }

    /** @param  list<int>  $buckets */
    private function weigh(array $buckets): float
    {
        $total = 0.0;

        foreach (self::DECAY as $index => [$days, $weight]) {
            $total += ($buckets[$index] ?? 0) * $weight;
        }

        return $total;
    }
}
