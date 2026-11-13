<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Campaign;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\Subscriber;
use Illuminate\Support\Carbon;

/**
 * Subscriber engagement heat table (spec §2.3.8 F-2.3.8-017).
 *
 * Two views of the same tracking rows, both read-only and both derived from
 * the source tables the rest of §2.3.8 uses:
 *
 *  - {@see rows()} — per subscriber: opens, clicks and a 0–4 intensity band
 *    used for the cell shading. The band is a RELATIVE rank inside this
 *    campaign (quintile of the busiest subscriber), never an absolute
 *    "engagement score" we would have had to invent a scale for.
 *  - {@see grid()} — a day-of-week × hour-of-day matrix of open hits, which
 *    is the classic heat map: when do these people actually read.
 */
class EngagementHeatTable
{
    public const DEFAULT_LIMIT = 100;

    /** Number of shading bands (0 = none, 1..4 = increasing intensity). */
    public const BANDS = 4;

    /**
     * @return array{
     *     rows: list<array{subscriber_uid: string, email: ?string, opens: int, clicks: int, last_at: ?string, band: int}>,
     *     max: int
     * }
     */
    public function rows(Campaign $campaign, int $limit = self::DEFAULT_LIMIT): array
    {
        $opens = CampaignTrackOpen::query()
            ->where('campaign_uid', $campaign->uid)
            ->selectRaw('subscriber_uid, count(*) as total, max(created_at) as last_at')
            ->groupBy('subscriber_uid')
            ->get();

        $clicks = CampaignTrackClick::query()
            ->where('campaign_uid', $campaign->uid)
            ->selectRaw('subscriber_uid, count(*) as total, max(created_at) as last_at')
            ->groupBy('subscriber_uid')
            ->get()
            ->keyBy('subscriber_uid');

        $merged = [];

        foreach ($opens as $row) {
            $merged[(string) $row->subscriber_uid] = [
                'opens' => (int) $row->total,
                'clicks' => 0,
                'last_at' => (string) $row->last_at,
            ];
        }

        foreach ($clicks as $uid => $row) {
            $uid = (string) $uid;
            $merged[$uid] ??= ['opens' => 0, 'clicks' => 0, 'last_at' => (string) $row->last_at];
            $merged[$uid]['clicks'] = (int) $row->total;
            $merged[$uid]['last_at'] = max($merged[$uid]['last_at'], (string) $row->last_at);
        }

        if ($merged === []) {
            return ['rows' => [], 'max' => 0];
        }

        // A click is deliberately weighted above an open: it is the stronger
        // signal, and the weighting is stated here rather than hidden.
        $weight = static fn (array $row): int => $row['opens'] + 2 * $row['clicks'];
        $max = max(array_map($weight, $merged));

        $emails = Subscriber::withoutTenancy()
            ->whereIn('uid', array_keys($merged))
            ->pluck('email', 'uid');

        $out = [];

        foreach ($merged as $uid => $row) {
            $out[] = [
                'subscriber_uid' => $uid,
                'email' => $emails[$uid] ?? null,
                'opens' => $row['opens'],
                'clicks' => $row['clicks'],
                'last_at' => $row['last_at'] === '' ? null : $row['last_at'],
                'band' => $this->band($weight($row), $max),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['band'] <=> $a['band']
            ?: $b['clicks'] <=> $a['clicks']
            ?: $b['opens'] <=> $a['opens']
            ?: strcmp((string) $a['email'], (string) $b['email']));

        return ['rows' => array_slice($out, 0, max(1, $limit)), 'max' => $max];
    }

    /**
     * Day-of-week (0 = Sunday) × hour-of-day matrix of open hits.
     *
     * @return array{matrix: array<int, array<int, int>>, max: int, total: int}
     */
    public function grid(Campaign $campaign): array
    {
        $matrix = [];

        for ($day = 0; $day < 7; $day++) {
            $matrix[$day] = array_fill(0, 24, 0);
        }

        $max = 0;
        $total = 0;

        // Bucketed in PHP, not SQL: strftime('%w') is SQLite-only and
        // DAYOFWEEK() is MySQL-only (D-03 forbids driver-specific SQL).
        foreach (CampaignTrackOpen::query()
            ->where('campaign_uid', $campaign->uid)
            ->pluck('created_at') as $createdAt) {
            if ($createdAt === null) {
                continue;
            }

            $moment = Carbon::parse($createdAt);
            $day = (int) $moment->dayOfWeek;
            $hour = (int) $moment->hour;

            $matrix[$day][$hour]++;
            $total++;
            $max = max($max, $matrix[$day][$hour]);
        }

        return ['matrix' => $matrix, 'max' => $max, 'total' => $total];
    }

    /** Relative intensity band 0..BANDS for a weighted value. */
    public function band(int $value, int $max): int
    {
        if ($value <= 0 || $max <= 0) {
            return 0;
        }

        return (int) max(1, min(self::BANDS, (int) ceil($value * self::BANDS / $max)));
    }
}
