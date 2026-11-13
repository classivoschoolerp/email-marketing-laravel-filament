<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Console\Commands\BackupsRunCommand;
use App\Models\UpgradeRun;
use App\Services\Ops\BackupManager;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * The automatic pre-upgrade backup hook (spec §6.2 F-6.2-004, WP-41).
 *
 * It calls the EXISTING WP-24 backup machinery — `backups:run`, whose body is
 * {@see BackupsRunCommand} on top of
 * {@see BackupManager} — rather than dumping the database a second way. One
 * backup implementation means one restore procedure, and the restore commands
 * the Backups page already prints are the same ones docs/upgrades.md points a
 * recovering operator at.
 *
 * TWO NON-OBVIOUS GUARANTEES, both of which exist because a backup that
 * silently did nothing is worse than no backup at all:
 *
 *  1. `backups:run` is a §0.6 cron job (D-40) and therefore returns SUCCESS
 *     without doing anything when an operator has disabled that job in the
 *     §3.25.11 settings. So this class does not trust the exit code: it diffs
 *     BackupManager::list() across the call and fails the upgrade unless a
 *     NEW database artefact actually appeared on disk.
 *  2. The uploads archive is optional (it depends on ext-zip and the Ops
 *     "include uploads" toggle) and its absence is reported, not fatal — the
 *     database dump is the artefact an upgrade rollback needs.
 */
class UpgradeBackup
{
    public function __construct(private readonly BackupManager $backups) {}

    /**
     * @return array{database: string|null, uploads: string|null, detail: string}
     *
     * @throws UpgradeException when no new database backup was produced
     */
    public function run(): array
    {
        $this->backups->ensureDirectory();

        $before = $this->index();

        try {
            Artisan::call('backups:run');
        } catch (Throwable $exception) {
            throw UpgradeException::atStep(UpgradeRun::STEP_BACKUP, __('upgrade.errors.backup_failed', [
                'error' => $exception->getMessage(),
            ]));
        }

        $created = array_values(array_filter(
            $this->backups->list(),
            fn (array $backup): bool => ! isset($before[$backup['name']]),
        ));

        $database = null;
        $uploads = null;

        foreach ($created as $backup) {
            if ($backup['type'] === 'database' && $database === null) {
                $database = (string) $backup['path'];
            }

            if ($backup['type'] === 'uploads' && $uploads === null) {
                $uploads = (string) $backup['path'];
            }
        }

        if ($database === null) {
            throw UpgradeException::atStep(
                UpgradeRun::STEP_BACKUP,
                __('upgrade.errors.backup_missing', ['path' => $this->backups->directory()])
            );
        }

        return [
            'database' => $database,
            'uploads' => $uploads,
            'detail' => $uploads === null
                ? __('upgrade.steps.backup_database_only', ['database' => basename($database)])
                : __('upgrade.steps.backup_detail', [
                    'database' => basename($database),
                    'uploads' => basename($uploads),
                ]),
        ];
    }

    /** @return array<string, true> */
    private function index(): array
    {
        $index = [];

        foreach ($this->backups->list() as $backup) {
            $index[(string) $backup['name']] = true;
        }

        return $index;
    }
}
