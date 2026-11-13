<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Services\Settings\SettingsReader;
use App\Support\Files;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Throwable;
use ZipArchive;

/**
 * §6.6 F-6.6-006 — built-in backup hooks (DB + uploads) with schedule and
 * retention (WP-24).
 *
 * WP-13 shipped the database half as `backups:run`. This service adds:
 *  - the **uploads/storage archive** (storage/app/public + storage/app/private,
 *    zipped) so a restore is not database-only;
 *  - **retention** driven by the Ops settings group (0 = keep forever, D-23);
 *  - the listing/download/delete API behind the admin Backups page.
 *
 * Restore is deliberately NOT automated: dropping a live database from a web
 * request is how installations get destroyed. The page shows exact, copyable
 * restore commands instead ({@see restoreInstructions()}).
 */
class BackupManager
{
    public const DATABASE_PREFIX = 'backup-';

    public const UPLOADS_PREFIX = 'uploads-';

    public function directory(): string
    {
        $directory = (string) (config('emp-sending.backups.directory') ?: storage_path('backups'));

        return Files::normalize($directory);
    }

    public function ensureDirectory(): string
    {
        $directory = $this->directory();

        if (! Files::accessible($directory)) {
            throw new RuntimeException(__('ops.files.open_basedir_denied', ['path' => $directory]));
        }

        File::ensureDirectoryExists($directory);

        return $directory;
    }

    /**
     * @return list<array{name: string, path: string, size: int, modified_at: string, type: string}>
     */
    public function list(): array
    {
        $directory = $this->directory();

        if (! Files::accessible($directory) || ! is_dir($directory)) {
            return [];
        }

        $backups = [];

        foreach (File::files($directory) as $file) {
            $name = $file->getFilename();

            if (! $this->isBackupName($name)) {
                continue;
            }

            $backups[] = [
                'name' => $name,
                'path' => Files::normalize($file->getPathname()),
                'size' => (int) $file->getSize(),
                'modified_at' => Carbon::createFromTimestamp($file->getMTime())->toDateTimeString(),
                'type' => str_starts_with($name, self::UPLOADS_PREFIX) ? 'uploads' : 'database',
            ];
        }

        usort($backups, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $backups;
    }

    public function isBackupName(string $name): bool
    {
        return preg_match('/^(backup|uploads)-[0-9]{8}-[0-9]{6}\.(sqlite|sql|zip)$/', $name) === 1;
    }

    /** Absolute path of a backup file, or null when the name is unknown/unsafe. */
    public function pathFor(string $name): ?string
    {
        if (! $this->isBackupName($name)) {
            return null;
        }

        $path = $this->directory().'/'.$name;

        return Files::accessible($path) && is_file($path) ? $path : null;
    }

    public function delete(string $name): bool
    {
        $path = $this->pathFor($name);

        return $path !== null && File::delete($path);
    }

    /**
     * Zip the upload trees into the backup directory (F-6.6-006 "DB + uploads").
     *
     * @return string|null the archive path, or null when there is nothing to
     *                     archive (or `ext-zip` is missing — the DB backup
     *                     must still succeed; {@see zipSupported()} drives the
     *                     honest warning on the admin Backups page).
     */
    public function backupUploads(string $stamp, ?string $directory = null): ?string
    {
        $directory ??= $this->ensureDirectory();

        $sources = array_values(array_filter([
            storage_path('app/public'),
            storage_path('app/private'),
        ], static fn (string $path): bool => is_dir($path)));

        if ($sources === []) {
            return null;
        }

        if (! $this->zipSupported()) {
            Log::warning(__('ops.backups.zip_unavailable'));

            return null;
        }

        $target = $directory.'/'.self::UPLOADS_PREFIX.$stamp.'.zip';
        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(__('ops.backups.zip_open_failed', ['path' => $target]));
        }

        $added = 0;

        foreach ($sources as $source) {
            $base = basename($source);

            foreach ((new Finder)->files()->in($source)->ignoreUnreadableDirs() as $file) {
                $zip->addFile($file->getPathname(), $base.'/'.$file->getRelativePathname());
                $added++;
            }
        }

        if ($added === 0) {
            $zip->addFromString('.empty', '');
        }

        $zip->close();

        return $target;
    }

    /**
     * Retention pruning across BOTH backup kinds. 0 = keep forever (D-23).
     *
     * @return int files deleted
     */
    public function prune(?int $days = null): int
    {
        $days ??= $this->retentionDays();

        if ($days <= 0) {
            return 0;
        }

        $directory = $this->directory();

        if (! Files::accessible($directory) || ! is_dir($directory)) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days)->getTimestamp();
        $deleted = 0;

        foreach (File::files($directory) as $file) {
            $name = $file->getFilename();

            if (! $this->isBackupName($name)) {
                continue;
            }

            if ($file->getMTime() < $cutoff && File::delete($file->getPathname())) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function zipSupported(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function retentionDays(): int
    {
        try {
            return SettingsReader::ops()->backupRetentionDays();
        } catch (Throwable) {
            return (int) config('emp-sending.backups.retention_days', 7);
        }
    }

    public function includeUploads(): bool
    {
        try {
            return SettingsReader::ops()->backupIncludeUploads();
        } catch (Throwable) {
            return true;
        }
    }

    public function totalBytes(): int
    {
        return array_sum(array_column($this->list(), 'size'));
    }

    /**
     * Copyable restore commands for the admin page — deliberately manual.
     *
     * @return list<array{title: string, command: string}>
     */
    public function restoreInstructions(): array
    {
        $connection = (string) config('database.default');
        $config = (array) config('database.connections.'.$connection, []);
        $driver = (string) ($config['driver'] ?? '');
        $directory = $this->directory();

        $steps = [
            [
                'title' => __('ops.backups.restore.step_stop'),
                'command' => 'php artisan down && supervisorctl stop emp-worker:*',
            ],
        ];

        $steps[] = match ($driver) {
            'sqlite' => [
                'title' => __('ops.backups.restore.step_database'),
                'command' => 'cp '.$directory.'/backup-YYYYMMDD-HHMMSS.sqlite '.(string) ($config['database'] ?? 'database/database.sqlite'),
            ],
            'mysql', 'mariadb' => [
                'title' => __('ops.backups.restore.step_database'),
                'command' => 'mysql -h '.(string) ($config['host'] ?? '127.0.0.1')
                    .' -u '.(string) ($config['username'] ?? 'emp')
                    .' -p '.(string) ($config['database'] ?? 'emp')
                    .' < '.$directory.'/backup-YYYYMMDD-HHMMSS.sql',
            ],
            default => [
                'title' => __('ops.backups.restore.step_database'),
                'command' => __('ops.backups.restore.unsupported_driver', ['driver' => $driver]),
            ],
        };

        $steps[] = [
            'title' => __('ops.backups.restore.step_uploads'),
            'command' => 'unzip -o '.$directory.'/uploads-YYYYMMDD-HHMMSS.zip -d '.storage_path('app'),
        ];

        $steps[] = [
            'title' => __('ops.backups.restore.step_start'),
            'command' => 'php artisan migrate --force && php artisan up && supervisorctl start emp-worker:*',
        ];

        return $steps;
    }
}
