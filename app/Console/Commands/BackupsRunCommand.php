<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCronJob;
use App\Models\CronRun;
use App\Services\Ops\BackupManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * `backups:run` (spec §0.6 F-0.6-017, daily): DB-agnostic database backup to
 * storage/backups (or BACKUPS_DIRECTORY) + retention pruning.
 *
 *  - sqlite  → plain file copy of the database file (D-03 dev/test target);
 *  - mysql/mariadb → mysqldump via Process using the connection credentials
 *    (production target; requires mysqldump on PATH — docs/deploy-mysql.md).
 *
 * Anything else (or an in-memory sqlite database) fails the run honestly —
 * recorded on the cron_runs row.
 *
 * WP-24 SURGICAL EDIT (spec §6.6 F-6.6-006 "DB **+ uploads** with schedule
 * and retention"): after the dump the run also zips the upload trees when the
 * Ops settings toggle is on, and retention pruning now goes through
 * App\Services\Ops\BackupManager so the admin Backups page, the settings
 * group and the cron job share ONE retention rule (0 = keep forever, D-23).
 * The processed count returned to cron_runs is the number of artefacts
 * written (1 = DB only, 2 = DB + uploads).
 */
class BackupsRunCommand extends Command
{
    use RunsAsCronJob;

    public const JOB_NAME = 'backups:run';

    protected $signature = 'backups:run';

    protected $description = 'Dump the database to the backup directory and prune old backups.';

    protected function process(CronRun $run): int
    {
        $manager = app(BackupManager::class);
        $directory = $manager->ensureDirectory();

        $connection = (string) config('database.default');
        $config = (array) config('database.connections.'.$connection, []);
        $driver = (string) ($config['driver'] ?? '');
        $stamp = Carbon::now()->format('Ymd-His');

        match ($driver) {
            'sqlite' => $this->backupSqlite($config, $directory, $stamp),
            'mysql', 'mariadb' => $this->backupMysql($config, $directory, $stamp),
            default => throw new RuntimeException(__('sending.backups.unsupported_driver', ['driver' => $driver])),
        };

        $written = 1;

        // WP-24 (F-6.6-006): uploads/storage archive alongside the dump.
        if ($manager->includeUploads() && $manager->backupUploads($stamp, $directory) !== null) {
            $written++;
        }

        // WP-24: single retention authority (Ops settings, 0 = keep forever).
        $manager->prune();

        return $written;
    }

    /** @param  array<string, mixed>  $config */
    private function backupSqlite(array $config, string $directory, string $stamp): void
    {
        $database = (string) ($config['database'] ?? '');

        if ($database === '' || $database === ':memory:' || ! File::exists($database)) {
            throw new RuntimeException(__('sending.backups.sqlite_unavailable', ['path' => $database]));
        }

        $target = $directory.DIRECTORY_SEPARATOR.'backup-'.$stamp.'.sqlite';

        if (! File::copy($database, $target)) {
            throw new RuntimeException(__('sending.backups.write_failed', ['path' => $target]));
        }
    }

    /** @param  array<string, mixed>  $config */
    private function backupMysql(array $config, string $directory, string $stamp): void
    {
        $target = $directory.DIRECTORY_SEPARATOR.'backup-'.$stamp.'.sql';

        $command = [
            'mysqldump',
            '--host='.(string) ($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 3306),
            '--user='.(string) ($config['username'] ?? ''),
            '--single-transaction',
            '--quick',
            '--routines',
            (string) ($config['database'] ?? ''),
        ];

        $process = new Process(
            $command,
            null,
            ['MYSQL_PWD' => (string) ($config['password'] ?? '')],
            null,
            3600,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(__('sending.backups.dump_failed', [
                'error' => trim($process->getErrorOutput()) ?: 'mysqldump exit '.$process->getExitCode(),
            ]));
        }

        File::put($target, $process->getOutput());
    }
}
