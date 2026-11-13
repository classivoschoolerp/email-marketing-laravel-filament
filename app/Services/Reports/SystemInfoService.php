<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

/**
 * PHP / system info for the §3.27 admin page (F-3.27-014, WP-17): versions,
 * extensions, ini limits, disk space, database size (driver-aware, D-03)
 * and storage usage. Read-only introspection — no caching, values are cheap
 * except storage usage which walks storage/ lazily.
 */
class SystemInfoService
{
    /** @return array<string, string> */
    public function versions(): array
    {
        $versions = [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'os' => php_uname('s').' '.php_uname('r'),
            'sapi' => php_sapi_name(),
        ];

        if (class_exists(InstalledVersions::class)
            && InstalledVersions::isInstalled('filament/filament')) {
            $versions['filament'] = (string) InstalledVersions::getPrettyVersion('filament/filament');
        }

        return $versions;
    }

    /** @return array<string, string> php.ini limits that matter operationally */
    public function limits(): array
    {
        return [
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'max_input_vars' => (string) ini_get('max_input_vars'),
        ];
    }

    /** @return list<string> */
    public function extensions(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_FLAG_CASE | SORT_STRING);

        return $extensions;
    }

    /** @return array<string, string> */
    public function drivers(): array
    {
        return [
            'database' => (string) DB::connection()->getDriverName(),
            'queue' => (string) config('queue.default'),
            'cache' => (string) config('cache.default'),
            'session' => (string) config('session.driver'),
            'mail' => (string) config('mail.default'),
        ];
    }

    /**
     * Database size in bytes — driver-aware (D-03): file size on SQLite,
     * information_schema on MySQL/MariaDB, pg_database_size on PostgreSQL;
     * null when the driver exposes no cheap answer.
     */
    public function databaseSizeBytes(): ?int
    {
        $connection = DB::connection();

        switch ($connection->getDriverName()) {
            case 'sqlite':
                $path = (string) $connection->getConfig('database');

                return $path !== '' && $path !== ':memory:' && is_file($path) ? (int) filesize($path) : null;

            case 'mysql':
            case 'mariadb':
                $row = $connection->selectOne(
                    'select sum(data_length + index_length) as size from information_schema.tables where table_schema = ?',
                    [(string) $connection->getConfig('database')],
                );

                return $row !== null && $row->size !== null ? (int) $row->size : null;

            case 'pgsql':
                $row = $connection->selectOne('select pg_database_size(current_database()) as size');

                return $row !== null ? (int) $row->size : null;

            default:
                return null;
        }
    }

    /** @return array{free: ?int, total: ?int} disk space at the app root, bytes */
    public function diskSpace(): array
    {
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());

        return [
            'free' => $free === false ? null : (int) $free,
            'total' => $total === false ? null : (int) $total,
        ];
    }

    /** @return array<string, int> storage directory sizes in bytes */
    public function storageUsage(): array
    {
        return [
            'storage/app' => $this->directorySize(storage_path('app')),
            'storage/logs' => $this->directorySize(storage_path('logs')),
            'storage/framework' => $this->directorySize(storage_path('framework')),
        ];
    }

    /**
     * §3.25.2 / WP-13 log retention windows surfaced read-only
     * (F-3.27-015): logs:prune enforces these nightly; 0 = keep forever
     * (D-23). WP-22's GDPR settings group supersedes as the operator UI.
     *
     * @return array<string, int>
     */
    public function retention(): array
    {
        return [
            'campaign_delivery_log_days' => (int) config('emp-sending.retention.campaign_delivery_log_days', 90),
            'campaign_track_days' => (int) config('emp-sending.retention.campaign_track_days', 180),
            'cron_run_days' => (int) config('emp-sending.retention.cron_run_days', 30),
            'idempotency_key_days' => (int) config('emp-sending.retention.idempotency_key_days', 30),
        ];
    }

    public function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }

    private function directorySize(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $size = 0;

        foreach ((new Finder)->files()->in($path)->ignoreUnreadableDirs() as $file) {
            $size += (int) $file->getSize();
        }

        return $size;
    }
}
