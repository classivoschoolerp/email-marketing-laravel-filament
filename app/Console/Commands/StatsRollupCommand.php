<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCronJob;
use App\Models\CronRun;
use App\Services\Cron\HeartbeatMonitor;
use App\Services\Stats\CampaignStatsRollup;
use App\Services\SystemEmails\QuotaNotifier;
use Illuminate\Console\Command;

/**
 * `stats:rollup` (spec §0.6 F-0.6-008, hourly): aggregates
 * campaign_delivery_logs + campaign_track_* into campaign_stats_daily
 * (consumed by WP-17/18 reports/dashboards), backfilling campaign_id on the
 * WP-09 track rows on the way. Piggybacks the cron-heartbeat staleness check
 * (F-0.6-018) so a broken heartbeat alerts admins within the hour.
 */
class StatsRollupCommand extends Command
{
    use RunsAsCronJob;

    public const JOB_NAME = 'stats:rollup';

    protected $signature = 'stats:rollup {--days=2 : UTC days to recompute (today backwards)}';

    protected $description = 'Aggregate campaign delivery/track logs into the daily stats rollup.';

    protected function process(CronRun $run): int
    {
        app(HeartbeatMonitor::class)->checkAndAlert();

        // Quota warning/reached notifications ride the hourly rollup (F-3.15-008).
        app(QuotaNotifier::class)->checkAll();

        $days = max(1, (int) $this->option('days'));

        return app(CampaignStatsRollup::class)->rollup(null, $days);
    }
}
