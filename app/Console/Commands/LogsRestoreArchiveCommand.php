<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LogArchive;
use App\Services\Ops\LogArchiver;
use Illuminate\Console\Command;
use Throwable;

/**
 * `logs:restore-archive` (spec §4.6 F-4.6-008, WP-42) — read a cold-storage
 * archive back into its table.
 *
 * Deliberately NOT a cron job: it is an operator action taken in response to
 * a dispute or an audit, and adding a name here would not be one of the 17
 * canonical §0.6 job names (D-40). Run with no id to list what is available.
 */
class LogsRestoreArchiveCommand extends Command
{
    protected $signature = 'logs:restore-archive {id? : The log_archives row to restore}';

    protected $description = 'Restore an archived log file back into its table, or list the available archives.';

    public function handle(LogArchiver $archiver): int
    {
        $id = $this->argument('id');

        if ($id === null) {
            return $this->listArchives();
        }

        $archive = LogArchive::query()->find((int) $id);

        if ($archive === null) {
            $this->error(__('ops.archive.not_found', ['id' => (string) $id]));

            return self::FAILURE;
        }

        try {
            $inserted = $archiver->restore($archive);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(__('ops.archive.restored', [
            'rows' => (string) $inserted,
            'table' => (string) $archive->table_name,
        ]));

        return self::SUCCESS;
    }

    private function listArchives(): int
    {
        $archives = LogArchive::query()->orderByDesc('id')->limit(50)->get();

        if ($archives->isEmpty()) {
            $this->info(__('ops.archive.empty'));

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'table', 'rows', 'bytes', 'from', 'until', 'disk', 'path', 'restored'],
            $archives->map(fn (LogArchive $archive): array => [
                $archive->id,
                $archive->table_name,
                $archive->rows_count,
                $archive->bytes,
                $archive->period_start?->toDateTimeString() ?? '-',
                $archive->period_end?->toDateTimeString() ?? '-',
                $archive->disk,
                $archive->path,
                $archive->restored_at?->toDateTimeString() ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
