<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Campaign;
use App\Models\CampaignBounceLog;
use App\Models\CampaignComplaintLog;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignForward;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUnsubscribe;
use App\Models\CampaignTrackUrl;
use App\Models\ConversionEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Single source of truth for every §2.3.8 campaign-report number (WP-17).
 *
 * Reads the SOURCE tables directly — campaign_delivery_logs,
 * campaign_track_* (keyed by campaign uid, always populated by the WP-09
 * endpoints) and the WP-15 bounce/complaint logs. Per D-64 the daily rollup
 * (campaign_stats_daily) is itself recomputed from these tables, so reading
 * the source keeps report + rollup consistent and makes unique counts exact
 * (per-day uniques cannot be summed across days). READ-ONLY by design: this
 * service must never write into the WP-13 pipeline's tables.
 *
 * Engagement breakdowns (device/os/browser/client/geo) aggregate the open
 * hits' parsed-UA and geo columns; geo stays in the "unknown" bucket until a
 * GeoIP provider is configured (D-10) — rendered honestly, never invented.
 */
class CampaignReportService
{
    /** Report tab identifiers (report tables + CSV exports). */
    public const TABS = ['opens', 'clicks', 'top_links', 'bounces', 'unsubscribes', 'complaints', 'delivery'];

    /* ================================================================ *
     *  Header stats (F-2.3.8-001)
     * ================================================================ */

    /**
     * WP-45 appends the four §2.12/§2.3.8 revenue keys (`conversions`,
     * `orders`, `revenue`, `revenue_per_recipient`). They are computed HERE
     * rather than alongside, because this method is the single source of
     * truth every consumer already reads — the exporter's summary sheet, the
     * §4.2 account roll-up and the A/B comparison all inherit them unedited.
     *
     * @return array{
     *     recipients: int, delivered: int, failed: int, skipped: int,
     *     bounced: int, bounced_hard: int, bounced_soft: int, bounced_internal: int,
     *     opens: int, unique_opens: int, open_rate: float,
     *     clicks: int, unique_clicks: int, click_rate: float, ctor: float,
     *     unsubscribes: int, complaints: int, forwards: int, forwarders: int,
     *     conversions: int, orders: int, revenue: int, revenue_per_recipient: int,
     *     conversion_rate: float, currency: string
     * }
     */
    public function headerStats(Campaign $campaign): array
    {
        $delivery = CampaignDeliveryLog::query()
            ->where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $delivered = (int) ($delivery[CampaignDeliveryLog::STATUS_SENT] ?? 0);
        $failed = (int) ($delivery[CampaignDeliveryLog::STATUS_FAILED] ?? 0)
            + (int) ($delivery[CampaignDeliveryLog::STATUS_RETRYING] ?? 0);
        $skipped = (int) ($delivery[CampaignDeliveryLog::STATUS_SKIPPED] ?? 0);

        // The WP-13 counter is authoritative while sending; the log-row count
        // is the fallback for campaigns whose counters were never stamped.
        $recipients = (int) $campaign->delivery_total;

        if ($recipients === 0) {
            $recipients = (int) $delivery->sum();
        }

        $bounces = CampaignBounceLog::query()
            ->where('campaign_id', $campaign->id)
            ->selectRaw('bounce_type, count(*) as total')
            ->groupBy('bounce_type')
            ->pluck('total', 'bounce_type');

        $opens = (int) CampaignTrackOpen::query()->where('campaign_uid', $campaign->uid)->count();
        $uniqueOpens = (int) CampaignTrackOpen::query()->where('campaign_uid', $campaign->uid)
            ->distinct()->count('subscriber_uid');

        $clicks = (int) CampaignTrackClick::query()->where('campaign_uid', $campaign->uid)->count();
        $uniqueClicks = (int) CampaignTrackClick::query()->where('campaign_uid', $campaign->uid)
            ->distinct()->count('subscriber_uid');

        // WP-45 (§2.3.8 F-2.3.8-002 / §2.12 F-2.12-007): revenue & orders
        // attributed to this campaign.
        $revenue = $this->revenueStats($campaign);

        return [
            'recipients' => $recipients,
            'delivered' => $delivered,
            'failed' => $failed,
            'skipped' => $skipped,
            'bounced' => (int) $bounces->sum(),
            'bounced_hard' => (int) ($bounces['hard'] ?? 0),
            'bounced_soft' => (int) ($bounces['soft'] ?? 0),
            'bounced_internal' => (int) ($bounces['internal'] ?? 0),
            'opens' => $opens,
            'unique_opens' => $uniqueOpens,
            'open_rate' => $this->rate($uniqueOpens, $delivered),
            'clicks' => $clicks,
            'unique_clicks' => $uniqueClicks,
            'click_rate' => $this->rate($uniqueClicks, $delivered),
            'ctor' => $this->rate($uniqueClicks, $uniqueOpens),
            'unsubscribes' => (int) CampaignTrackUnsubscribe::query()->where('campaign_uid', $campaign->uid)
                ->distinct()->count('subscriber_uid'),
            'complaints' => (int) CampaignComplaintLog::query()->where('campaign_id', $campaign->id)->count(),
            // WP-35 (§1.6 F-1.6-002 / §1.7 F-1.7-004): forward tracking is
            // live. `forwards` counts recorded forwards, `forwarders` the
            // distinct subscribers who used the form.
            'forwards' => (int) CampaignForward::query()->where('campaign_uid', $campaign->uid)->count(),
            'forwarders' => (int) CampaignForward::query()
                ->where('campaign_uid', $campaign->uid)
                ->whereNotNull('subscriber_uid')
                ->distinct()
                ->count('subscriber_uid'),
            // WP-45 §2.3.8 F-2.3.8-002.
            'conversions' => $revenue['conversions'],
            'orders' => $revenue['orders'],
            'revenue' => $revenue['revenue'],
            'revenue_per_recipient' => $delivered > 0 ? intdiv($revenue['revenue'], $delivered) : 0,
            'conversion_rate' => $this->rate($revenue['converters'], $delivered),
            'currency' => $revenue['currency'],
        ];
    }

    /* ================================================================ *
     *  Revenue & orders attributed (F-2.3.8-002 / F-2.12-007, WP-45)
     * ================================================================ */

    /**
     * Money and conversions attributed to this campaign, read from the ONE
     * conversion ledger (`conversion_events`) that RevenueAttributor and the
     * goal pixel both write to — so this report, the A/B comparison table and
     * the §4.2 account roll-up can never disagree.
     *
     * `revenue` is integer MINOR UNITS. `currency` is the campaign's dominant
     * one: a shop selling in several currencies cannot have its money summed
     * into a single number honestly, so the report reports the currency that
     * produced the most rows and `mixed_currency` says when others exist —
     * inventing an FX rate on a factual report would be worse than saying so.
     *
     * `orders` counts conversions that came from a real synced order;
     * `conversions` counts every attributed conversion including pixel-only
     * goals (a signup has no order).
     *
     * @return array{
     *     conversions: int, converters: int, orders: int, revenue: int,
     *     currency: string, mixed_currency: bool
     * }
     */
    public function revenueStats(Campaign $campaign): array
    {
        $rows = ConversionEvent::query()
            ->where('customer_id', $campaign->customer_id)
            ->where('campaign_uid', $campaign->uid)
            ->selectRaw('currency,'
                .' count(*) as conversions,'
                .' count(distinct subscriber_uid) as converters,'
                .' sum(case when store_order_id is null then 0 else 1 end) as orders,'
                .' coalesce(sum(value), 0) as revenue')
            ->groupBy('currency')
            ->orderByDesc('conversions')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'conversions' => 0,
                'converters' => 0,
                'orders' => 0,
                'revenue' => 0,
                'currency' => 'USD',
                'mixed_currency' => false,
            ];
        }

        $dominant = $rows->first();

        return [
            // Counts are currency-agnostic, so they sum across every row.
            'conversions' => (int) $rows->sum('conversions'),
            'converters' => (int) $rows->sum('converters'),
            'orders' => (int) $rows->sum('orders'),
            // Money is NOT summed across currencies — see the docblock.
            'revenue' => (int) $dominant->revenue,
            'currency' => (string) $dominant->currency,
            'mixed_currency' => $rows->count() > 1,
        ];
    }

    /**
     * Per-subscriber conversion counts for a campaign, keyed by subscriber
     * uid. Feeds the §2.3.6 A/B `conversions` criterion, which needs the
     * figure split by variant — and the variant join lives on subscriber uid.
     *
     * @param  list<string>  $subscriberUids
     * @return array<string, array{conversions: int, revenue: int}>
     */
    public function conversionsBySubscriber(Campaign $campaign, array $subscriberUids = []): array
    {
        $query = ConversionEvent::query()
            ->where('customer_id', $campaign->customer_id)
            ->where('campaign_uid', $campaign->uid)
            ->whereNotNull('subscriber_uid');

        if ($subscriberUids !== []) {
            $query->whereIn('subscriber_uid', $subscriberUids);
        }

        $rows = $query
            ->selectRaw('subscriber_uid, count(*) as conversions, coalesce(sum(value), 0) as revenue')
            ->groupBy('subscriber_uid')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->subscriber_uid] = [
                'conversions' => (int) $row->conversions,
                'revenue' => (int) $row->revenue,
            ];
        }

        return $out;
    }

    /* ================================================================ *
     *  Charts (F-2.3.8-011/012)
     * ================================================================ */

    /**
     * Opens- or clicks-over-time buckets, oldest first, empty buckets filled.
     *
     * Bucketing uses date()/substr() only — both exist on SQLite and MySQL
     * (D-03: no driver-specific SQL). Hourly = last 48 hours; daily = since
     * the send started (falling back to the first event), capped at 60 days.
     *
     * @return Collection<string, int> label => events
     */
    public function series(Campaign $campaign, string $metric, string $granularity = 'daily'): Collection
    {
        $model = $metric === 'clicks' ? CampaignTrackClick::query() : CampaignTrackOpen::query();
        $query = $model->where('campaign_uid', $campaign->uid);

        if ($granularity === 'hourly') {
            $start = Carbon::now()->startOfHour()->subHours(47);

            // 'YYYY-MM-DD HH' — substr works identically on SQLite and MySQL.
            $rows = $query
                ->where('created_at', '>=', $start)
                ->selectRaw('substr(created_at, 1, 13) as bucket, count(*) as total')
                ->groupBy('bucket')
                ->pluck('total', 'bucket');

            $buckets = [];

            for ($i = 0; $i < 48; $i++) {
                $hour = $start->copy()->addHours($i);
                $buckets[$hour->format('Y-m-d H').':00'] = (int) ($rows[$hour->format('Y-m-d H')] ?? 0);
            }

            return collect($buckets);
        }

        $first = $campaign->send_started_at
            ?? $query->clone()->min('created_at')
            ?? Carbon::now();

        $start = Carbon::parse($first)->startOfDay();
        $days = min(60, (int) $start->diffInDays(Carbon::now()->startOfDay()) + 1);
        $start = Carbon::now()->startOfDay()->subDays($days - 1);

        $rows = $query
            ->where('created_at', '>=', $start)
            ->selectRaw('date(created_at) as bucket, count(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $buckets = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i)->format('Y-m-d');
            $buckets[$day] = (int) ($rows[$day] ?? 0);
        }

        return collect($buckets);
    }

    /* ================================================================ *
     *  Engagement breakdowns (F-2.3.8-013..016)
     * ================================================================ */

    /**
     * Device / OS / browser / email-client split of the open hits
     * (F-2.3.8-014/015/016). Null column values land in the "unknown" bucket.
     *
     * @return list<array{label: string, count: int, percent: float}>
     */
    public function breakdown(Campaign $campaign, string $dimension): array
    {
        if (! in_array($dimension, ['device', 'os', 'browser', 'client'], true)) {
            return [];
        }

        $rows = CampaignTrackOpen::query()
            ->where('campaign_uid', $campaign->uid)
            ->selectRaw($dimension.' as label, count(*) as total')
            ->groupBy('label')
            ->orderByDesc('total')
            ->pluck('total', 'label');

        return $this->withShare($rows);
    }

    /**
     * Geo report rows (F-2.3.8-013): country/region/city of the open hits.
     * All-null rows collapse into a single honest "unknown" bucket until a
     * GeoIP provider is configured (D-10).
     *
     * @return list<array{country: ?string, region: ?string, city: ?string, count: int, percent: float}>
     */
    public function geoBreakdown(Campaign $campaign, int $limit = 50): array
    {
        $rows = CampaignTrackOpen::query()
            ->where('campaign_uid', $campaign->uid)
            ->selectRaw('country, region, city, count(*) as total')
            ->groupBy('country', 'region', 'city')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $total = (int) CampaignTrackOpen::query()->where('campaign_uid', $campaign->uid)->count();

        return $rows->map(fn ($row): array => [
            'country' => $row->country,
            'region' => $row->region,
            'city' => $row->city,
            'count' => (int) $row->total,
            'percent' => $this->rate((int) $row->total, $total),
        ])->values()->all();
    }

    /**
     * Per-COUNTRY open totals (F-2.3.8-013, the world-map view of the geo
     * report). Rows with no country land in a single null bucket, which the
     * map reports honestly as "unknown" instead of dropping.
     *
     * @return list<array{country: ?string, count: int, percent: float}>
     */
    public function countryBreakdown(Campaign $campaign): array
    {
        $rows = CampaignTrackOpen::query()
            ->where('campaign_uid', $campaign->uid)
            ->selectRaw('country, count(*) as total')
            ->groupBy('country')
            ->orderByDesc('total')
            ->get();

        $total = (int) $rows->sum('total');

        return $rows->map(fn ($row): array => [
            'country' => $row->country === null || trim((string) $row->country) === ''
                ? null
                : (string) $row->country,
            'count' => (int) $row->total,
            'percent' => $this->rate((int) $row->total, $total),
        ])->values()->all();
    }

    /**
     * Top clicked links with % share of all clicks (F-2.3.8-006).
     *
     * @return list<array{url: string, clicks: int, unique_clickers: int, percent: float}>
     */
    public function topLinks(Campaign $campaign, ?int $limit = null): array
    {
        $totalClicks = (int) CampaignTrackClick::query()->where('campaign_uid', $campaign->uid)->count();

        $rows = CampaignTrackUrl::query()
            ->where('campaign_track_urls.campaign_uid', $campaign->uid)
            ->leftJoin('campaign_track_clicks', 'campaign_track_clicks.url_id', '=', 'campaign_track_urls.id')
            ->selectRaw('campaign_track_urls.url as url,'
                .' count(campaign_track_clicks.id) as clicks,'
                .' count(distinct campaign_track_clicks.subscriber_uid) as unique_clickers')
            ->groupBy('campaign_track_urls.id', 'campaign_track_urls.url')
            ->orderByDesc('clicks')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get();

        return $rows->map(fn ($row): array => [
            'url' => (string) $row->url,
            'clicks' => (int) $row->clicks,
            'unique_clickers' => (int) $row->unique_clickers,
            'percent' => $this->rate((int) $row->clicks, $totalClicks),
        ])->values()->all();
    }

    /* ================================================================ *
     *  Tab queries (F-2.3.8-004..010) — consumed by the Filament tables
     * ================================================================ */

    /**
     * Per-subscriber open aggregation (F-2.3.8-004): times opened,
     * first/last open, last ip/device. Aggregates (min/max) keep the query
     * ONLY_FULL_GROUP_BY-safe on MySQL; subscribers are left-joined so hits
     * from since-deleted subscribers still show (uid fallback).
     */
    public function opensQuery(Campaign $campaign): Builder
    {
        return CampaignTrackOpen::query()
            ->where('campaign_track_opens.campaign_uid', $campaign->uid)
            ->leftJoin('subscribers', 'subscribers.uid', '=', 'campaign_track_opens.subscriber_uid')
            ->selectRaw('min(campaign_track_opens.id) as id,'
                .' campaign_track_opens.subscriber_uid as subscriber_uid,'
                .' max(subscribers.email) as subscriber_email,'
                .' count(*) as times_opened,'
                .' min(campaign_track_opens.created_at) as first_open_at,'
                .' max(campaign_track_opens.created_at) as last_open_at,'
                .' max(campaign_track_opens.ip) as ip,'
                .' max(campaign_track_opens.device) as device')
            ->groupBy('campaign_track_opens.subscriber_uid');
    }

    /** Per-URL click aggregation (F-2.3.8-005). */
    public function clicksQuery(Campaign $campaign): Builder
    {
        return CampaignTrackUrl::query()
            ->where('campaign_track_urls.campaign_uid', $campaign->uid)
            ->withCount(['clicks as clicks_count'])
            ->addSelect([
                'unique_clickers' => CampaignTrackClick::query()
                    ->selectRaw('count(distinct subscriber_uid)')
                    ->whereColumn('campaign_track_clicks.url_id', 'campaign_track_urls.id'),
            ]);
    }

    /** Who-clicked-what drill-down for one tracked URL (F-2.3.8-005). */
    public function clickersQuery(CampaignTrackUrl $url): Builder
    {
        return CampaignTrackClick::query()
            ->where('campaign_track_clicks.url_id', $url->id)
            ->leftJoin('subscribers', 'subscribers.uid', '=', 'campaign_track_clicks.subscriber_uid')
            ->selectRaw('min(campaign_track_clicks.id) as id,'
                .' campaign_track_clicks.subscriber_uid as subscriber_uid,'
                .' max(subscribers.email) as subscriber_email,'
                .' count(*) as clicks_count,'
                .' max(campaign_track_clicks.created_at) as last_click_at')
            ->groupBy('campaign_track_clicks.subscriber_uid');
    }

    public function bouncesQuery(Campaign $campaign): Builder
    {
        return CampaignBounceLog::query()
            ->where('campaign_id', $campaign->id)
            ->with('subscriber');
    }

    /**
     * Unsubscribe hits (F-2.3.8-008) joined to the subscriber for the stored
     * unsubscribe reason (subscribers.unsubscribe_reason, F-1.5-007).
     */
    public function unsubscribesQuery(Campaign $campaign): Builder
    {
        return CampaignTrackUnsubscribe::query()
            ->where('campaign_track_unsubscribes.campaign_uid', $campaign->uid)
            ->leftJoin('subscribers', 'subscribers.uid', '=', 'campaign_track_unsubscribes.subscriber_uid')
            ->select('campaign_track_unsubscribes.*')
            ->addSelect(
                'subscribers.email as subscriber_email',
                'subscribers.unsubscribe_reason as unsubscribe_reason',
            );
    }

    public function complaintsQuery(Campaign $campaign): Builder
    {
        return CampaignComplaintLog::query()
            ->where('campaign_id', $campaign->id)
            ->with('subscriber');
    }

    public function deliveryLogsQuery(Campaign $campaign): Builder
    {
        return CampaignDeliveryLog::query()
            ->where('campaign_id', $campaign->id)
            ->with(['subscriber', 'deliveryServer']);
    }

    /* ================================================================ *
     *  Helpers
     * ================================================================ */

    /** Percentage (1 decimal); 0.0 when the denominator is empty. */
    public function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : 0.0;
    }

    /**
     * @param  Collection<int|string, int|string>  $rows  label => count
     * @return list<array{label: string, count: int, percent: float}>
     */
    private function withShare(Collection $rows): array
    {
        $total = (int) $rows->sum();
        $out = [];

        foreach ($rows as $label => $count) {
            $out[] = [
                'label' => $label === null || $label === '' ? 'unknown' : (string) $label,
                'count' => (int) $count,
                'percent' => $this->rate((int) $count, $total),
            ];
        }

        return $out;
    }
}
