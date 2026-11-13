<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cross-campaign analytics (spec §2.3.11) plus the two §2.3.8 comparison
 * rows: "vs account average / previous campaign" (F-2.3.8-003) and
 * group-level aggregate reporting (§2.3.10 F-2.3.10-003).
 *
 * EVERY figure comes from CampaignReportService::headerStats() — this class
 * never re-implements a metric, it only selects campaigns, aggregates and
 * ranks. That is what keeps a campaign's open rate identical whether it is
 * read on its own report page, in a side-by-side table or inside an average.
 *
 * Only SENT campaigns are eligible: a half-sent campaign's rates are a moving
 * target and averaging them in would be comparing a finished race to a
 * running one.
 */
class CampaignComparisonService
{
    /** Comparable metrics, in table order. */
    public const METRICS = [
        'recipients', 'delivered', 'bounced',
        'unique_opens', 'open_rate',
        'unique_clicks', 'click_rate', 'ctor',
        'unsubscribes', 'complaints', 'forwards',
    ];

    /** Metrics offered on the trend chart (rates only — volumes are not comparable). */
    public const TREND_METRICS = ['open_rate', 'click_rate', 'ctor'];

    public const MAX_CAMPAIGNS = 12;

    public function __construct(private readonly CampaignReportService $reports) {}

    /* ================================================================ *
     *  F-2.3.11-001 — side-by-side metric table
     * ================================================================ */

    /**
     * @param  list<int>  $campaignIds
     * @return array{
     *     campaigns: list<array{id: int, name: string, subject: ?string, sent_at: ?string}>,
     *     rows: list<array{metric: string, values: list<float|int>}>
     * }
     */
    public function sideBySide(array $campaignIds): array
    {
        $campaigns = $this->resolve($campaignIds);
        $stats = [];
        $header = [];

        foreach ($campaigns as $campaign) {
            $stats[] = $this->reports->headerStats($campaign);
            $header[] = [
                'id' => (int) $campaign->id,
                'name' => (string) $campaign->name,
                'subject' => $campaign->subject,
                'sent_at' => $campaign->send_finished_at?->toDateTimeString()
                    ?? $campaign->send_started_at?->toDateTimeString(),
            ];
        }

        $rows = [];

        foreach (self::METRICS as $metric) {
            $rows[] = [
                'metric' => $metric,
                'values' => array_map(
                    fn (array $row): float|int => $row[$metric] ?? 0,
                    $stats,
                ),
            ];
        }

        return ['campaigns' => $header, 'rows' => $rows];
    }

    /* ================================================================ *
     *  F-2.3.11-002 — trend across campaigns over time
     * ================================================================ */

    /**
     * One point per campaign, oldest send first — the honest x-axis for
     * "how did this metric move campaign after campaign".
     *
     * @param  list<int>  $campaignIds
     * @return list<array{label: string, date: ?string, value: float}>
     */
    public function trend(array $campaignIds, string $metric = 'open_rate'): array
    {
        if (! in_array($metric, self::TREND_METRICS, true)) {
            $metric = 'open_rate';
        }

        $points = [];

        foreach ($this->resolve($campaignIds) as $campaign) {
            $stats = $this->reports->headerStats($campaign);

            $points[] = [
                'label' => (string) $campaign->name,
                'date' => $this->sentAt($campaign)?->toDateString(),
                'value' => (float) ($stats[$metric] ?? 0),
                'sort' => $this->sentAt($campaign)?->getTimestamp() ?? 0,
            ];
        }

        usort($points, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(static fn (array $point): array => [
            'label' => $point['label'],
            'date' => $point['date'],
            'value' => $point['value'],
        ], $points);
    }

    /* ================================================================ *
     *  F-2.3.11-003 — best / worst performing subject lines
     * ================================================================ */

    /**
     * Ranked by open rate. Campaigns with no delivered mail are excluded:
     * a 0.0 % open rate from zero delivered is not a bad subject line, it is
     * no data.
     *
     * @return array{best: list<array{campaign_id: int, subject: string, open_rate: float, click_rate: float, delivered: int}>, worst: list<array{campaign_id: int, subject: string, open_rate: float, click_rate: float, delivered: int}>}
     */
    public function subjectPerformance(int $customerId, int $limit = 5): array
    {
        $rows = [];

        foreach ($this->sentCampaignsFor($customerId) as $campaign) {
            $subject = trim((string) $campaign->subject);

            if ($subject === '') {
                continue;
            }

            $stats = $this->reports->headerStats($campaign);

            if ($stats['delivered'] < 1) {
                continue;
            }

            $rows[] = [
                'campaign_id' => (int) $campaign->id,
                'subject' => $subject,
                'open_rate' => $stats['open_rate'],
                'click_rate' => $stats['click_rate'],
                'delivered' => $stats['delivered'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['open_rate'] <=> $a['open_rate']
            ?: $b['delivered'] <=> $a['delivered']
            ?: $a['campaign_id'] <=> $b['campaign_id']);

        $limit = max(1, $limit);

        return [
            'best' => array_slice($rows, 0, $limit),
            'worst' => array_values(array_reverse(array_slice($rows, -$limit))),
        ];
    }

    /* ================================================================ *
     *  F-2.3.11-004 — best send day / hour
     * ================================================================ */

    /**
     * Open rate grouped by the day-of-week and hour the campaign STARTED
     * sending. Buckets with no campaign are reported as null rather than 0 —
     * "we never sent on a Sunday" is not "Sundays perform badly".
     *
     * @return array{
     *     by_day: array<int, array{day: int, campaigns: int, open_rate: ?float, click_rate: ?float}>,
     *     by_hour: array<int, array{hour: int, campaigns: int, open_rate: ?float, click_rate: ?float}>,
     *     best_day: ?int, best_hour: ?int
     * }
     */
    public function sendTimeAnalysis(int $customerId): array
    {
        $days = [];
        $hours = [];

        foreach ($this->sentCampaignsFor($customerId) as $campaign) {
            $sentAt = $this->sentAt($campaign);

            if ($sentAt === null) {
                continue;
            }

            $stats = $this->reports->headerStats($campaign);

            if ($stats['delivered'] < 1) {
                continue;
            }

            $this->accumulate($days, (int) $sentAt->dayOfWeek, $stats);
            $this->accumulate($hours, (int) $sentAt->hour, $stats);
        }

        return [
            'by_day' => $this->finalise($days, 'day', 7),
            'by_hour' => $this->finalise($hours, 'hour', 24),
            'best_day' => $this->bestBucket($days),
            'best_hour' => $this->bestBucket($hours),
        ];
    }

    /* ================================================================ *
     *  F-2.3.8-003 — vs account average / previous campaign
     * ================================================================ */

    /**
     * @return array{
     *     stats: array<string, float|int>,
     *     average: array<string, float>|null, average_basis: int,
     *     previous: array{id: int, name: string, stats: array<string, float|int>}|null
     * }
     */
    public function benchmarks(Campaign $campaign): array
    {
        $stats = $this->reports->headerStats($campaign);
        $peers = $this->sentCampaignsFor((int) $campaign->customer_id)
            ->reject(fn (Campaign $other): bool => (int) $other->id === (int) $campaign->id)
            ->values();

        $average = null;

        if ($peers->isNotEmpty()) {
            $totals = [];

            foreach ($peers as $peer) {
                $peerStats = $this->reports->headerStats($peer);

                foreach (self::METRICS as $metric) {
                    $totals[$metric] = ($totals[$metric] ?? 0) + (float) ($peerStats[$metric] ?? 0);
                }
            }

            $average = [];

            foreach (self::METRICS as $metric) {
                $average[$metric] = round(($totals[$metric] ?? 0) / $peers->count(), 1);
            }
        }

        $previous = $this->previousCampaign($campaign);

        return [
            'stats' => $stats,
            'average' => $average,
            'average_basis' => $peers->count(),
            'previous' => $previous === null ? null : [
                'id' => (int) $previous->id,
                'name' => (string) $previous->name,
                'stats' => $this->reports->headerStats($previous),
            ],
        ];
    }

    /** The customer's most recent sent campaign before this one. */
    public function previousCampaign(Campaign $campaign): ?Campaign
    {
        $sentAt = $this->sentAt($campaign);

        return $this->sentCampaignsFor((int) $campaign->customer_id)
            ->reject(fn (Campaign $other): bool => (int) $other->id === (int) $campaign->id)
            ->filter(function (Campaign $other) use ($sentAt): bool {
                $otherAt = $this->sentAt($other);

                return $sentAt === null || ($otherAt !== null && $otherAt->lessThan($sentAt));
            })
            ->sortByDesc(fn (Campaign $other): int => $this->sentAt($other)?->getTimestamp() ?? 0)
            ->first();
    }

    /* ================================================================ *
     *  F-2.3.10-003 — group-level aggregate reporting
     * ================================================================ */

    /**
     * @return array{campaigns: int, totals: array<string, int>, rates: array<string, float>}
     */
    public function groupAggregate(CampaignGroup $group): array
    {
        $campaigns = Campaign::withoutTenancy()
            ->where('customer_id', $group->customer_id)
            ->where('campaign_group_id', $group->id)
            ->where('status', CampaignStatus::Sent)
            ->get();

        $totals = [
            'recipients' => 0, 'delivered' => 0, 'bounced' => 0,
            'opens' => 0, 'unique_opens' => 0,
            'clicks' => 0, 'unique_clicks' => 0,
            'unsubscribes' => 0, 'complaints' => 0, 'forwards' => 0,
        ];

        foreach ($campaigns as $campaign) {
            $stats = $this->reports->headerStats($campaign);

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) ($stats[$key] ?? 0);
            }
        }

        return [
            'campaigns' => $campaigns->count(),
            'totals' => $totals,
            // Rates recomputed from the TOTALS, never averaged from per-campaign
            // rates — averaging rates would weight a 10-recipient campaign the
            // same as a 100,000-recipient one.
            'rates' => [
                'open_rate' => $this->reports->rate($totals['unique_opens'], $totals['delivered']),
                'click_rate' => $this->reports->rate($totals['unique_clicks'], $totals['delivered']),
                'ctor' => $this->reports->rate($totals['unique_clicks'], $totals['unique_opens']),
                'bounce_rate' => $this->reports->rate($totals['bounced'], $totals['recipients']),
                'unsubscribe_rate' => $this->reports->rate($totals['unsubscribes'], $totals['delivered']),
            ],
        ];
    }

    /* ================================================================ *
     *  Helpers
     * ================================================================ */

    /**
     * @param  list<int>  $campaignIds
     * @return Collection<int, Campaign>
     */
    public function resolve(array $campaignIds): Collection
    {
        $ids = array_slice(array_values(array_unique(array_map(intval(...), $campaignIds))), 0, self::MAX_CAMPAIGNS);

        if ($ids === []) {
            return collect();
        }

        // The tenant scope stays ON: a customer may only compare their own
        // campaigns, and a foreign id simply does not resolve.
        return Campaign::query()
            ->whereIn('id', $ids)
            ->where('status', CampaignStatus::Sent)
            ->get()
            ->sortBy(fn (Campaign $campaign): int => array_search((int) $campaign->id, $ids, true) ?: 0)
            ->values();
    }

    /** @return Collection<int, Campaign> */
    public function sentCampaignsFor(int $customerId): Collection
    {
        return Campaign::withoutTenancy()
            ->where('customer_id', $customerId)
            ->where('status', CampaignStatus::Sent)
            ->orderByDesc('send_finished_at')
            ->orderByDesc('id')
            ->get();
    }

    public function sentAt(Campaign $campaign): ?Carbon
    {
        $moment = $campaign->send_finished_at ?? $campaign->send_started_at ?? $campaign->send_at;

        return $moment === null ? null : Carbon::parse($moment);
    }

    /** @param array<int, array{campaigns: int, opens: int, clicks: int, delivered: int}> $buckets */
    private function accumulate(array &$buckets, int $key, array $stats): void
    {
        $buckets[$key] ??= ['campaigns' => 0, 'opens' => 0, 'clicks' => 0, 'delivered' => 0];
        $buckets[$key]['campaigns']++;
        $buckets[$key]['opens'] += (int) $stats['unique_opens'];
        $buckets[$key]['clicks'] += (int) $stats['unique_clicks'];
        $buckets[$key]['delivered'] += (int) $stats['delivered'];
    }

    /**
     * @param  array<int, array{campaigns: int, opens: int, clicks: int, delivered: int}>  $buckets
     * @return array<int, array{day?: int, hour?: int, campaigns: int, open_rate: ?float, click_rate: ?float}>
     */
    private function finalise(array $buckets, string $keyName, int $size): array
    {
        $out = [];

        for ($i = 0; $i < $size; $i++) {
            $bucket = $buckets[$i] ?? null;

            $out[$i] = [
                $keyName => $i,
                'campaigns' => $bucket['campaigns'] ?? 0,
                'open_rate' => $bucket === null ? null : $this->reports->rate($bucket['opens'], $bucket['delivered']),
                'click_rate' => $bucket === null ? null : $this->reports->rate($bucket['clicks'], $bucket['delivered']),
            ];
        }

        return $out;
    }

    /** @param array<int, array{campaigns: int, opens: int, clicks: int, delivered: int}> $buckets */
    private function bestBucket(array $buckets): ?int
    {
        $best = null;
        $bestRate = -1.0;

        foreach ($buckets as $key => $bucket) {
            if ($bucket['delivered'] < 1) {
                continue;
            }

            $rate = $this->reports->rate($bucket['opens'], $bucket['delivered']);

            if ($rate > $bestRate) {
                $bestRate = $rate;
                $best = $key;
            }
        }

        return $best;
    }
}
