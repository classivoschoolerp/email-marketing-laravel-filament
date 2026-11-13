<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Plugins\CoreVersion;
use App\Services\Ops\BackupManager;
use App\Services\Reports\SystemInfoService;
use App\Support\Files;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;
use ZipArchive;

/**
 * Pre-upgrade safety checks (spec §6.2 F-6.2-003, WP-41).
 *
 * Every check here BLOCKS the upgrade when it fails. There is no `--force`,
 * and adding one would defeat the row: the four the spec names (disk space,
 * PHP version, DB connectivity, pending migrations) are precisely the
 * conditions under which an upgrade half-applies and takes the install down.
 *
 * Reuses the existing introspection services rather than re-deriving any of
 * it — {@see SystemInfoService} for disk/PHP/driver facts (WP-17),
 * {@see BackupManager} for where the pre-upgrade backup will land (WP-24),
 * and {@see CoreVersion} for the version window (WP-36, D-95).
 *
 * Non-blocking observations are reported as `warning`: they are shown to the
 * operator but never stop the run, because a warning that stops things is
 * just a failure with a friendlier name.
 */
class UpgradePreflight
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const WARN = 'warning';

    public function __construct(
        private readonly SystemInfoService $info,
        private readonly BackupManager $backups,
        private readonly UpgradeTarget $target,
    ) {}

    /**
     * @return list<array{key: string, status: string, detail: string, blocking: bool}>
     */
    public function run(?ReleaseInfo $release = null): array
    {
        return [
            $this->diskSpace($release),
            $this->phpVersion($release),
            $this->databaseConnectivity(),
            $this->pendingMigrations(),
            $this->coreVersionWindow($release),
            $this->targetWritable(),
            $this->backupDirectory(),
            $this->zipExtension(),
            $this->maintenanceDriver(),
        ];
    }

    /**
     * @param  list<array{key: string, status: string, detail: string, blocking: bool}>  $checks
     * @return list<string> human-readable reasons the upgrade is blocked
     */
    public function blockers(array $checks): array
    {
        $blockers = [];

        foreach ($checks as $check) {
            if ($check['status'] === self::FAIL && $check['blocking']) {
                $blockers[] = __('upgrade.checks.'.$check['key'].'.label').': '.$check['detail'];
            }
        }

        return $blockers;
    }

    /** @param list<array{key: string, status: string, detail: string, blocking: bool}> $checks */
    public function passes(array $checks): bool
    {
        return $this->blockers($checks) === [];
    }

    /** @return array{key: string, status: string, detail: string, blocking: bool} */
    private function diskSpace(?ReleaseInfo $release): array
    {
        $space = $this->info->diskSpace();
        $minimumMb = (int) config('emp-upgrade.preflight.min_free_disk_mb', 512);

        // The archive is downloaded, extracted to staging AND snapshotted for
        // rollback, so budget three copies on top of the operator's floor.
        $archiveMb = $release?->sizeBytes !== null ? (int) ceil($release->sizeBytes / 1048576) : 0;
        $requiredMb = $minimumMb + ($archiveMb * 3);

        if ($space['free'] === null) {
            return $this->check('disk_space', self::WARN, __('upgrade.checks.disk_space.unknown'), blocking: false);
        }

        $freeMb = (int) floor($space['free'] / 1048576);

        return $this->check(
            'disk_space',
            $freeMb >= $requiredMb ? self::PASS : self::FAIL,
            __('upgrade.checks.disk_space.detail', ['free' => $freeMb, 'required' => $requiredMb]),
        );
    }

    /** @return array{key: string, status: string, detail: string, blocking: bool} */
    private function phpVersion(?ReleaseInfo $release): array
    {
        $required = $release?->minPhp ?? (string) config('emp-upgrade.preflight.min_php_version', '8.2.0');

        return $this->check(
            'php_version',
            version_compare(PHP_VERSION, $required, '>=') ? self::PASS : self::FAIL,
            __('upgrade.checks.php_version.detail', ['current' => PHP_VERSION, 'required' => $required]),
        );
    }

    /** @return array{key: string, status: string, detail: string, blocking: bool} */
    private function databaseConnectivity(): array
    {
        try {
            DB::connection()->getPdo();
            DB::connection()->select('select 1');
        } catch (Throwable $exception) {
            return $this->check('database', self::FAIL, __('upgrade.checks.database.failed', [
                'error' => $exception->getMessage(),
            ]));
        }

        return $this->check('database', self::PASS, __('upgrade.checks.database.detail', [
            'driver' => (string) DB::connection()->getDriverName(),
        ]));
    }

    /**
     * A pending migration BEFORE an upgrade means the install is already
     * half-deployed. Layering a new release's migrations on top of that is
     * how a database ends up in a state no `migrate` can reason about.
     *
     * @return array{key: string, status: string, detail: string, blocking: bool}
     */
    private function pendingMigrations(): array
    {
        try {
            $pending = $this->pendingMigrationNames();
        } catch (Throwable $exception) {
            return $this->check('pending_migrations', self::FAIL, __('upgrade.checks.pending_migrations.failed', [
                'error' => $exception->getMessage(),
            ]));
        }

        if ($pending === []) {
            return $this->check('pending_migrations', self::PASS, __('upgrade.checks.pending_migrations.none'));
        }

        return $this->check('pending_migrations', self::FAIL, __('upgrade.checks.pending_migrations.detail', [
            'count' => count($pending),
            'first' => $pending[0],
        ]));
    }

    /** @return list<string> */
    public function pendingMigrationNames(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $repository = $migrator->getRepository();

        if (! $repository->repositoryExists()) {
            // No migrations table at all — this is not an upgradeable install.
            throw new \RuntimeException(__('upgrade.checks.pending_migrations.no_repository'));
        }

        $paths = $migrator->paths();
        $paths[] = database_path('migrations');

        // getMigrationFiles() returns [migration name => absolute path], which
        // is exactly the key space migrations.migration stores. Migrator's own
        // pendingMigrations() is protected, so the diff is done here rather
        // than by reaching into the framework.
        $files = $migrator->getMigrationFiles(array_values(array_unique($paths)));

        return array_values(array_diff(array_keys($files), $repository->getRan()));
    }

    /** @return array{key: string, status: string, detail: string, blocking: bool} */
    private function coreVersionWindow(?ReleaseInfo $release): array
    {
        $current = CoreVersion::current();

        if ($release === null) {
            return $this->check('core_version', self::WARN, __('upgrade.checks.core_version.no_release', [
                'current' => $current,
            ]), blocking: false);
        }

        if (! $release->isNewerThan($current)) {
            return $this->check('core_version', self::FAIL, __('upgrade.checks.core_version.not_newer', [
                'current' => $current,
                'release' => $release->version,
            ]));
        }

        if ($release->requiresCore !== null && ! CoreVersion::satisfies($current, $release->requiresCore)) {
            return $this->check('core_version', self::FAIL, __('upgrade.checks.core_version.unsatisfied', [
                'current' => $current,
                'constraint' => $release->requiresCore,
            ]));
        }

        if (! $release->isInstallable()) {
            return $this->check('core_version', self::FAIL, __('upgrade.checks.core_version.not_installable', [
                'release' => $release->version,
            ]));
        }

        return $this->check('core_version', self::PASS, __('upgrade.checks.core_version.detail', [
            'current' => $current,
            'release' => $release->version,
        ]));
    }

    /** @return array{key: string, status: string, detail: string, blocking: bool} */
    private function targetWritable(): array
    {
        try {
            $path = $this->target->assertSafe();
        } catch (UpgradeException $exception) {
            return $this->check('target_writable', self::FAIL, $exception->getMessage());
        }

        $inspection = Files::inspect($path);

        if (! $inspection['allowed']) {
            return $this->check('target_writable', self::FAIL, __('ops.files.open_basedir_denied', ['path' => $path]));
        }

        if (! $inspection['exists'] || ! $inspection['writable']) {
            return $this->check('target_writable', self::FAIL, __('upgrade.checks.target_writable.failed', [
                'path' => $path,
            ]));
        }

        return $this->check('target_writable', self::PASS, __('upgrade.checks.target_writable.detail', ['path' => $path]));
    }

    /** @return array{key: string, status: string, detail: string, blocking: bool} */
    private function backupDirectory(): array
    {
        $directory = $this->backups->directory();
        $inspection = Files::inspect($directory);

        // The directory is created on demand by BackupManager::ensureDirectory,
        // so an absent directory is fine as long as its parent is writable.
        $probe = $inspection['exists'] ? $directory : dirname($directory);
        $probeInspection = Files::inspect($probe);

        if (! $probeInspection['allowed'] || ! $probeInspection['writable']) {
            return $this->check('backup_directory', self::FAIL, __('upgrade.checks.backup_directory.failed', [
                'path' => $directory,
            ]));
        }

        return $this->check('backup_directory', self::PASS, __('upgrade.checks.backup_directory.detail', [
            'path' => $directory,
        ]));
    }

    /** @return array{key: string, status: string, detail: string, blocking: bool} */
    private function zipExtension(): array
    {
        return $this->check(
            'zip_extension',
            class_exists(ZipArchive::class) ? self::PASS : self::FAIL,
            __('upgrade.checks.zip_extension.detail'),
        );
    }

    /**
     * Non-blocking: maintenance mode is best-effort. On a shared host where
     * `artisan down` cannot write storage/framework/down the upgrade still
     * runs — it just runs without the shield, and the operator is told.
     *
     * @return array{key: string, status: string, detail: string, blocking: bool}
     */
    private function maintenanceDriver(): array
    {
        if (! (bool) config('emp-upgrade.maintenance.enabled', true)) {
            return $this->check('maintenance', self::WARN, __('upgrade.checks.maintenance.disabled'), blocking: false);
        }

        $writable = Files::inspect(storage_path('framework'))['writable'];

        return $this->check(
            'maintenance',
            $writable ? self::PASS : self::WARN,
            $writable ? __('upgrade.checks.maintenance.detail') : __('upgrade.checks.maintenance.unwritable'),
            blocking: false,
        );
    }

    /** @return array{key: string, status: string, detail: string, blocking: bool} */
    private function check(string $key, string $status, string $detail, bool $blocking = true): array
    {
        return ['key' => $key, 'status' => $status, 'detail' => $detail, 'blocking' => $blocking];
    }
}
