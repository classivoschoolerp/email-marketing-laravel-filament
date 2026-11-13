<?php

declare(strict_types=1);

namespace App\Services\Campaigns\AbTest;

use App\Enums\AbWinnerCriteria;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUnsubscribe;
use App\Models\CampaignVariant;
use App\Services\Reports\CampaignReportService;
use Illuminate\Support\Facades\DB;

/**
 * Per-variant statistics for the §2.3.6 comparison table + chart
 * (F-2.3.6-009) and for the winner decision (F-2.3.6-006/011).
 *
 * Attribution: `campaign_variant_recipients` is the durable record of which
 * subscriber received which variant, and every campaign_track_* table is keyed
 * by `subscriber_uid` — so a single join answers "which variant produced this
 * open" with no subscribers hop and no guesswork.
 *
 * Percentages go through CampaignReportService::rate() so a variant's open
 * rate is rounded exactly like the campaign's own open rate on the report
 * page — the two must never disagree by a rounding step.
 */
final class AbTestStats
{
    public function __construct(private readonly CampaignReportService $reports) {}

    /**
     * One row per variant, in composer order.
     *
     * @return list<array{
     *     variant_id: int, label: string, weight: int, is_winner: bool,
     *     recipients: int, delivered: int,
     *     opens: int, unique_opens: int, open_rate: float,
     *     clicks: int, unique_clicks: int, click_rate: float, ctor: float,
     *     unsubscribes: int, criterion_value: float
     * }>
     */
    public function table(Campaign $campaign): array
    {
        $criteria = $this->criteria($campaign);
        $rows = [];

        foreach ($campaign->variants as $variant) {
            $rows[] = $this->row($campaign, $variant, $criteria);
        }

        return $rows;
    }

    /**
     * @return array{
     *     variant_id: int, label: string, weight: int, is_winner: bool,
     *     recipients: int, delivered: int,
     *     opens: int, unique_opens: int, open_rate: float,
     *     clicks: int, unique_clicks: int, click_rate: float, ctor: float,
     *     unsubscribes: int, criterion_value: float
     * }
     */
    public function row(Campaign $campaign, CampaignVariant $variant, ?AbWinnerCriteria $criteria = null): array
    {
        $criteria ??= $this->criteria($campaign);

        $recipients = (int) $variant->recipientAssignments()->count();

        $delivered = (int) CampaignDeliveryLog::query()
            ->where('campaign_delivery_logs.campaign_id', $campaign->id)
            ->where('campaign_delivery_logs.status', CampaignDeliveryLog::STATUS_SENT)
            ->whereIn('campaign_delivery_logs.subscriber_id', $this->subscriberIds($variant))
            ->count();

        $opens = $this->events(CampaignTrackOpen::query()->getModel()->getTable(), $campaign, $variant);
        $clicks = $this->events(CampaignTrackClick::query()->getModel()->getTable(), $campaign, $variant);
        $unsubs = $this->events(CampaignTrackUnsubscribe::query()->getModel()->getTable(), $campaign, $variant);

        // WP-45 (§2.12): the conversions/revenue criterion F-2.3.6-006 named.
        // Same join, same attribution seam as every other event on this row —
        // conversion_events is keyed by subscriber_uid too, so which variant
        // produced a purchase is one join away and no guesswork.
        $conversions = $this->conversions($campaign, $variant);

        $stats = [
            'variant_id' => (int) $variant->id,
            'label' => (string) $variant->label,
            'weight' => (int) $variant->weight,
            'is_winner' => (bool) $variant->is_winner,
            'recipients' => $recipients,
            'delivered' => $delivered,
            'opens' => $opens['total'],
            'unique_opens' => $opens['unique'],
            'open_rate' => $this->reports->rate($opens['unique'], $delivered),
            'clicks' => $clicks['total'],
            'unique_clicks' => $clicks['unique'],
            'click_rate' => $this->reports->rate($clicks['unique'], $delivered),
            'ctor' => $this->reports->rate($clicks['unique'], $opens['unique']),
            'unsubscribes' => $unsubs['unique'],
            'conversions' => $conversions['conversions'],
            'converters' => $conversions['converters'],
            'revenue' => $conversions['revenue'],
            'currency' => $conversions['currency'],
        ];

        [$successKey, $trialKey] = $criteria->proportion();

        $stats['criterion_value'] = $this->reports->rate(
            (int) ($stats[$successKey] ?? 0),
            (int) ($stats[$trialKey] ?? 0),
        );

        return $stats;
    }

    /**
     * The (successes, trials) pair the z-test consumes for one variant
     * (F-2.3.6-011). Returns null for a criterion with no data source.
     *
     * @return array{0: int, 1: int}|null
     */
    public function proportionFor(Campaign $campaign, CampaignVariant $variant): ?array
    {
        $criteria = $this->criteria($campaign);

        // WP-45: "available in the product" AND "available for THIS campaign"
        // — a campaign with no conversion source still has no proportion to
        // measure. See AbWinnerCriteria's docblock.
        if (! $criteria->isAvailable() || ! $criteria->isAvailableFor($campaign)) {
            return null;
        }

        $row = $this->row($campaign, $variant, $criteria);
        [$successKey, $trialKey] = $criteria->proportion();

        return [(int) ($row[$successKey] ?? 0), (int) ($row[$trialKey] ?? 0)];
    }

    public function criteria(Campaign $campaign): AbWinnerCriteria
    {
        return AbWinnerCriteria::tryFrom((string) $campaign->ab_winner_criteria)
            ?? AbWinnerCriteria::UniqueOpens;
    }

    /**
     * Conversions, converters and revenue for ONE variant (WP-45,
     * F-2.3.6-006). Revenue is integer minor units; the currency is the
     * variant's dominant one, following the same "never sum across
     * currencies" rule CampaignReportService::revenueStats() documents.
     *
     * @return array{conversions: int, converters: int, revenue: int, currency: string}
     */
    private function conversions(Campaign $campaign, CampaignVariant $variant): array
    {
        $row = DB::table('conversion_events')
            ->join(
                'campaign_variant_recipients',
                'campaign_variant_recipients.subscriber_uid',
                '=',
                'conversion_events.subscriber_uid',
            )
            ->where('conversion_events.customer_id', $campaign->customer_id)
            ->where('conversion_events.campaign_uid', $campaign->uid)
            ->where('campaign_variant_recipients.campaign_variant_id', $variant->id)
            ->selectRaw('conversion_events.currency as currency,'
                .' count(*) as conversions,'
                .' count(distinct conversion_events.subscriber_uid) as converters,'
                .' coalesce(sum(conversion_events.value), 0) as revenue')
            ->groupBy('conversion_events.currency')
            ->orderByDesc('conversions')
            ->first();

        return [
            'conversions' => (int) ($row->conversions ?? 0),
            'converters' => (int) ($row->converters ?? 0),
            'revenue' => (int) ($row->revenue ?? 0),
            'currency' => (string) ($row->currency ?? 'USD'),
        ];
    }

    /**
     * Total + unique event counts for one variant on one tracking table.
     *
     * @return array{total: int, unique: int}
     */
    private function events(string $table, Campaign $campaign, CampaignVariant $variant): array
    {
        $row = DB::table($table)
            ->join('campaign_variant_recipients', 'campaign_variant_recipients.subscriber_uid', '=', $table.'.subscriber_uid')
            ->where($table.'.campaign_uid', $campaign->uid)
            ->where('campaign_variant_recipients.campaign_variant_id', $variant->id)
            ->selectRaw('count(*) as total, count(distinct '.$table.'.subscriber_uid) as uniques')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'unique' => (int) ($row->uniques ?? 0),
        ];
    }

    /** @return \Illuminate\Database\Query\Builder */
    private function subscriberIds(CampaignVariant $variant)
    {
        return DB::table('campaign_variant_recipients')
            ->where('campaign_variant_id', $variant->id)
            ->select('subscriber_id');
    }
}
