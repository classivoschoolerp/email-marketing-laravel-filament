<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCronJob;
use App\Models\CronRun;
use App\Models\ExportJob;
use App\Services\Ops\LogArchiver;
use App\Services\Settings\SettingsReader;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * `logs:prune` (spec §0.6 F-0.6-014, nightly): enforces the retention policy
 * on the high-volume log tables. campaign_track_urls is deliberately NOT
 * pruned — rows are needed to resolve clicks for as long as the campaign
 * exists.
 *
 * WP-22 (spec §3.25.16 F-3.25.16-002 + §4.5 F-4.5-007): retention is now
 * operator-facing. Windows come from the `privacy` settings group per data
 * type (delivery logs / opens / clicks / exports) and from the `security`
 * group for the audit trail (login, impersonation, API request and consent
 * logs), each falling back to the original config/emp-sending.php value when
 * the setting is NULL or the settings tables are not migrated yet. 0 still
 * means "keep forever" (D-23).
 *
 * WP-42 (§4.6 F-4.6-008): with `emp-archive.enabled` on, allow-listed tables
 * are streamed to cold storage before they are deleted — see
 * {@see LogArchiver} and {@see archiveAndPruneTable()}. Retention windows are
 * unchanged; what changes is whether the data still exists somewhere after
 * the window closes.
 */
class LogsPruneCommand extends Command
{
    use RunsAsCronJob;

    public const JOB_NAME = 'logs:prune';

    protected $signature = 'logs:prune';

    protected $description = 'Delete delivery/track/cron/audit log rows past their retention window.';

    protected function process(CronRun $run): int
    {
        $deleted = 0;

        foreach ($this->retentionMap() as $table => $days) {
            $deleted += $this->pruneTable($table, $days);
        }

        $deleted += $this->pruneExports();

        return $deleted;
    }

    /**
     * Table => retention days. WP-22 settings first, config fallback.
     *
     * @return array<string, int>
     */
    private function retentionMap(): array
    {
        $trackFallback = (int) config('emp-sending.retention.campaign_track_days', 180);

        $map = [
            'campaign_delivery_logs' => $this->privacy(
                static fn (): int => SettingsReader::privacy()->deliveryLogRetentionDays(),
                (int) config('emp-sending.retention.campaign_delivery_log_days', 90),
            ),
            'campaign_track_opens' => $this->privacy(
                static fn (): int => SettingsReader::privacy()->openRetentionDays(),
                $trackFallback,
            ),
            'campaign_track_clicks' => $this->privacy(
                static fn (): int => SettingsReader::privacy()->clickRetentionDays(),
                $trackFallback,
            ),
            // Unsubscribe tracking follows the click window (same §1.7 family).
            'campaign_track_unsubscribes' => $this->privacy(
                static fn (): int => SettingsReader::privacy()->clickRetentionDays(),
                $trackFallback,
            ),
            'cron_runs' => (int) config('emp-sending.retention.cron_run_days', 30),
            'job_idempotency_keys' => (int) config('emp-sending.retention.idempotency_key_days', 30),
        ];

        // F-3.25.15-007 — audit trail retention: sign-ins, impersonation and
        // the §4.5 consent audit trail share one window. api_request_logs is
        // deliberately excluded — it has its own §3.25.7 retention setting
        // and its own lottery-driven pruning (WP-19).
        $auditDays = $this->privacy(
            static fn (): int => SettingsReader::security()->auditLogRetentionDays(),
            365,
        );

        foreach (['login_logs', 'impersonation_logs', 'consent_events'] as $auditTable) {
            $map[$auditTable] = $auditDays;
        }

        return $map;
    }

    private function pruneTable(string $table, int $days): int
    {
        if ($days <= 0 || ! Schema::hasTable($table)) {
            return 0; // 0 = keep forever (D-23)
        }

        $cutoff = Carbon::now()->subDays($days);

        $archiver = app(LogArchiver::class);

        if ($archiver->enabled() && $archiver->archives($table)) {
            return $this->archiveAndPruneTable($archiver, $table, $cutoff);
        }

        $deleted = 0;

        // Chunked deletes keep transactions/locks small on MySQL.
        do {
            $batch = DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->limit(5000)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }

    /**
     * F-4.6-008 archival path (WP-42): the same chunked prune, except each
     * batch is written to cold storage BEFORE it is deleted, and the delete
     * targets the exact primary keys that were archived. Ordered that way on
     * purpose — a crash can duplicate an archive, never lose a row.
     */
    private function archiveAndPruneTable(LogArchiver $archiver, string $table, Carbon $cutoff): int
    {
        $deleted = 0;
        $size = $archiver->chunkSize();

        do {
            $rows = DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($size)
                ->get()
                ->all();

            if ($rows === []) {
                break;
            }

            $archiver->archiveBatch($table, $rows);

            $ids = array_map(static fn (object $row): mixed => $row->id, $rows);

            $deleted += DB::table($table)->whereIn('id', $ids)->delete();
        } while (count($rows) === $size);

        return $deleted;
    }

    /**
     * F-3.25.16-002 exports: the privacy window force-expires export files
     * older than N days. The export_jobs row survives as history (same
     * contract as `exports:prune`, F-2.2.8-005); only the file goes.
     * 0 = leave expiry entirely to the §3.25.2 setting.
     */
    private function pruneExports(): int
    {
        $days = $this->privacy(
            static fn (): int => SettingsReader::privacy()->exportRetentionDays(),
            0,
        );

        if ($days <= 0 || ! Schema::hasTable('export_jobs')) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);
        $pruned = 0;

        $expired = ExportJob::withoutTenancy()
            ->where('status', ExportJob::STATUS_COMPLETED)
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($expired as $export) {
            if ($export->file_path !== null) {
                try {
                    Storage::disk('local')->delete($export->file_path);
                } catch (Throwable) {
                    // A missing file must not abort the nightly prune.
                }
            }

            $export->forceFill([
                'status' => ExportJob::STATUS_EXPIRED,
                'file_path' => null,
            ])->save();

            $pruned++;
        }

        return $pruned;
    }

    /**
     * Settings-first with a config fallback: the nightly cron must not fail
     * on an installation whose settings tables do not exist yet.
     *
     * @param  callable(): int  $reader
     */
    private function privacy(callable $reader, int $fallback): int
    {
        try {
            return $reader();
        } catch (Throwable) {
            return $fallback;
        }
    }
}
