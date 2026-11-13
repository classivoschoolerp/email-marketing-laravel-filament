<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\Campaign;
use App\Models\CampaignStatsDaily;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * stats:rollup aggregation (spec §0.6 F-0.6-008, WP-13): recomputes the
 * campaign_stats_daily summary rows from campaign_delivery_logs +
 * campaign_track_* for a sliding window of UTC days. Recomputation is
 * idempotent (upsert per campaign/day) so it may run any number of times.
 * WP-17/18 reports read the rollup instead of scanning the log tables.
 *
 * Also backfills campaign_id on WP-09 track rows (they are inserted by the
 * public endpoints with the uid only).
 */
class CampaignStatsRollup
{
    /**
     * @param  list<int>|null  $campaignIds  restrict to these campaigns (null = all with activity)
     * @return int number of rollup rows written
     */
    public function rollup(?array $campaignIds = null, int $days = 2): int
    {
        $this->backfillTrackCampaignIds();

        $written = 0;
        $today = Carbon::now()->startOfDay();

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $today->copy()->subDays($i);
            $written += $this->rollupDay($day, $campaignIds);
        }

        return $written;
    }

    /** Backfill campaign_id (uid → id) on track rows inserted by WP-09. */
    public function backfillTrackCampaignIds(): int
    {
        $updated = 0;

        foreach (['campaign_track_opens', 'campaign_track_clicks', 'campaign_track_unsubscribes', 'campaign_track_urls'] as $table) {
            $uids = DB::table($table)->whereNull('campaign_id')->distinct()->pluck('campaign_uid');

            foreach ($uids as $uid) {
                $id = Campaign::withoutTenancy()->withTrashed()->where('uid', $uid)->value('id');

                if ($id !== null) {
                    $updated += DB::table($table)
                        ->whereNull('campaign_id')
                        ->where('campaign_uid', $uid)
                        ->update(['campaign_id' => $id]);
                }
            }
        }

        return $updated;
    }

    /** @param  list<int>|null  $campaignIds */
    private function rollupDay(Carbon $day, ?array $campaignIds): int
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $stats = [];
        $touch = function (int $campaignId) use (&$stats): void {
            $stats[$campaignId] ??= [
                'sent' => 0, 'failed' => 0, 'skipped' => 0,
                'opens' => 0, 'unique_opens' => 0,
                'clicks' => 0, 'unique_clicks' => 0,
                'unsubscribes' => 0,
            ];
        };

        $delivery = DB::table('campaign_delivery_logs')
            ->selectRaw('campaign_id, status, count(*) as events')
            ->whereBetween('created_at', [$from, $to])
            ->when($campaignIds !== null, fn ($q) => $q->whereIn('campaign_id', $campaignIds))
            ->groupBy('campaign_id', 'status')
            ->get();

        foreach ($delivery as $row) {
            $touch((int) $row->campaign_id);

            match ((string) $row->status) {
                'sent' => $stats[(int) $row->campaign_id]['sent'] = (int) $row->events,
                'failed', 'retrying' => $stats[(int) $row->campaign_id]['failed'] += (int) $row->events,
                'skipped' => $stats[(int) $row->campaign_id]['skipped'] = (int) $row->events,
                default => null,
            };
        }

        foreach ([
            'campaign_track_opens' => ['opens', 'unique_opens'],
            'campaign_track_clicks' => ['clicks', 'unique_clicks'],
        ] as $table => [$totalKey, $uniqueKey]) {
            $rows = DB::table($table)
                ->selectRaw('campaign_id, count(*) as total, count(distinct subscriber_uid) as uniques')
                ->whereNotNull('campaign_id')
                ->whereBetween('created_at', [$from, $to])
                ->when($campaignIds !== null, fn ($q) => $q->whereIn('campaign_id', $campaignIds))
                ->groupBy('campaign_id')
                ->get();

            foreach ($rows as $row) {
                $touch((int) $row->campaign_id);
                $stats[(int) $row->campaign_id][$totalKey] = (int) $row->total;
                $stats[(int) $row->campaign_id][$uniqueKey] = (int) $row->uniques;
            }
        }

        $unsubscribes = DB::table('campaign_track_unsubscribes')
            ->selectRaw('campaign_id, count(distinct subscriber_uid) as uniques')
            ->whereNotNull('campaign_id')
            ->whereBetween('created_at', [$from, $to])
            ->when($campaignIds !== null, fn ($q) => $q->whereIn('campaign_id', $campaignIds))
            ->groupBy('campaign_id')
            ->get();

        foreach ($unsubscribes as $row) {
            $touch((int) $row->campaign_id);
            $stats[(int) $row->campaign_id]['unsubscribes'] = (int) $row->uniques;
        }

        foreach ($stats as $campaignId => $columns) {
            // Pass the Carbon (not a Y-m-d string) so the lookup binding and
            // the cast-serialized stored value agree on every driver.
            CampaignStatsDaily::query()->updateOrCreate(
                ['campaign_id' => $campaignId, 'day' => $day->copy()->startOfDay()],
                $columns,
            );
        }

        return count($stats);
    }
}
