<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\ErrorLogWatcher;
use App\Services\Ops\PerformanceMonitor;
use App\Services\Ops\ResourceMonitor;
use App\Services\Ops\WorkerRegistry;
use App\Services\Settings\SettingsReader;
use Illuminate\Console\Command;
use Throwable;

/**
 * `ops:monitor` (spec §6.6 F-6.6-002/003/005/008, WP-24): the single
 * scheduled sweep behind the admin health features —
 *
 *  1. scan the application log for new ERROR+ entries and raise in-admin
 *     notifications (F-6.6-002);
 *  2. evaluate the disk/memory/DB-size thresholds and alert (F-6.6-005);
 *  3. prune slow-query rows past retention (F-6.6-003);
 *  4. prune stale worker rows (F-6.6-008).
 *
 * Deliberately NOT a §0.6 KNOWN_JOBS row (D-40 fixes that list): it is
 * monitoring, like `system:cron-heartbeat`, so it has no per-job toggle and
 * is scheduled from routes/modules/ops.php.
 */
class OpsMonitorCommand extends Command
{
    protected $signature = 'ops:monitor
        {--skip-errors : do not scan the log}
        {--skip-thresholds : do not evaluate resource thresholds}';

    protected $description = 'Scan errors, evaluate resource thresholds and prune ops tables (§6.6).';

    public function handle(
        ErrorLogWatcher $errors,
        ResourceMonitor $resources,
        PerformanceMonitor $performance,
        WorkerRegistry $workers,
    ): int {
        if (! $this->option('skip-errors')) {
            $count = $errors->scan();
            $this->info(__('ops.errors.scanned', ['count' => $count]));
        }

        if (! $this->option('skip-thresholds')) {
            $raised = $resources->alert();
            $this->info(__('ops.health.alerts_raised', ['count' => count($raised)]));
        }

        $pruned = $performance->pruneSlowQueries($this->slowQueryRetentionDays());
        $this->info(__('ops.performance.pruned', ['count' => $pruned]));

        $prunedWorkers = $workers->prune();
        $this->info(__('ops.workers.pruned', ['count' => $prunedWorkers]));

        return self::SUCCESS;
    }

    private function slowQueryRetentionDays(): int
    {
        try {
            return SettingsReader::ops()->slowQueryRetentionDays();
        } catch (Throwable) {
            return (int) config('emp-ops.ops.slow_query_retention_days', 7);
        }
    }
}
