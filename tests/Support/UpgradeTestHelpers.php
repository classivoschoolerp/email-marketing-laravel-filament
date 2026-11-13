<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Upgrade\UpgradeTarget;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * WP-41 test scaffolding (spec §6.2).
 *
 * THE RULE THIS FILE ENFORCES: an upgrade test never touches the real
 * installation. Every helper here points `emp-upgrade.target_path`,
 * `emp-upgrade.work_path` and the backup directory at throwaway temp
 * directories. {@see UpgradeTarget::assertSafe()} is the
 * second line of defence — it throws outright if a test forgets to call
 * useTempTarget() and the runner resolves base_path() under APP_ENV=testing.
 */
final class UpgradeTestHelpers
{
    /** @var list<string> */
    private static array $created = [];

    /**
     * A throwaway "installed application" the upgrade will be applied to,
     * pre-seeded with the files a real install has and the protected paths
     * F-6.2-006 must never touch.
     */
    public static function useTempTarget(): string
    {
        $root = self::tempDirectory('emp-upgrade-target-');
        $work = self::tempDirectory('emp-upgrade-work-');

        // A believable slice of an installed application.
        self::put($root.'/config/emp.php', "<?php return ['core_version' => '1.0.0'];\n");
        self::put($root.'/app/Services/Old.php', "<?php // v1.0.0\n");
        self::put($root.'/public/index.php', "<?php // v1.0.0 front controller\n");

        // Protected paths, each holding data an upgrade must preserve.
        self::put($root.'/.env', "APP_KEY=base64:local-secret\n");
        self::put($root.'/storage/app/plugins/acme-relay/plugin.json', '{"slug":"acme-relay"}');
        self::put($root.'/storage/app/themes/midnight/theme.json', '{"slug":"midnight"}');
        self::put($root.'/storage/app/backups/backup-20260101-000000.sqlite', 'PREVIOUS BACKUP');
        self::put($root.'/storage/app/public/customers/c1/logo.png', 'PNG');

        config([
            'emp-upgrade.target_path' => $root,
            'emp-upgrade.work_path' => $work,
        ]);

        return $root;
    }

    /**
     * Point the WP-24 backup machinery at temp paths so the pre-upgrade
     * backup hook can really run: `backups:run` copies the sqlite file named
     * by the connection config, and the test DB is `:memory:`.
     *
     * @return array{directory: string, database: string}
     */
    public static function useTempBackups(): array
    {
        $directory = self::tempDirectory('emp-upgrade-backups-');
        $database = self::tempDirectory('emp-upgrade-db-').'/database.sqlite';

        self::put($database, 'SQLITE FILE CONTENTS');

        config([
            'emp-sending.backups.directory' => $directory,
            'database.connections.sqlite.database' => $database,
        ]);

        return ['directory' => $directory, 'database' => $database];
    }

    /**
     * Build a release .zip from an in-memory file map (archive-relative
     * paths) and return [path, sha256].
     *
     * @param  array<string, string>  $files
     * @return array{path: string, checksum: string}
     */
    public static function releaseZip(array $files, ?string $prefix = null): array
    {
        $path = self::tempDirectory('emp-upgrade-zip-').'/release.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString(($prefix === null ? '' : rtrim($prefix, '/').'/').$name, $contents);
        }

        $zip->close();

        return ['path' => $path, 'checksum' => (string) hash_file('sha256', $path)];
    }

    /**
     * The default "good" release package: a new config/emp.php carrying the
     * new version, one changed source file and one new file.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public static function releaseFiles(string $version = '1.1.0', array $extra = []): array
    {
        return array_replace([
            'release.json' => json_encode(['version' => $version]),
            'config/emp.php' => "<?php return ['core_version' => '{$version}'];\n",
            'app/Services/Old.php' => "<?php // v{$version}\n",
            'app/Services/New.php' => "<?php // added in v{$version}\n",
            'public/index.php' => "<?php // v{$version} front controller\n",
        ], $extra);
    }

    /**
     * A release-feed document.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function feed(string $version, string $checksum, array $overrides = []): array
    {
        return array_replace([
            'version' => $version,
            'released_at' => '2026-08-13T09:00:00+00:00',
            'download_url' => 'https://releases.example.test/emp-'.$version.'.zip',
            'checksum' => $checksum,
            'checksum_algo' => 'sha256',
            'changelog' => "### {$version}\n- Faster sending\n- Fixed a bounce parser bug",
            'min_php' => '8.2.0',
            'requires_core' => '>=1.0.0',
            'mandatory' => false,
        ], $overrides);
    }

    public static function put(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        file_put_contents($path, $contents);
    }

    public static function tempDirectory(string $prefix): string
    {
        $path = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.$prefix.Str::random(10);

        @mkdir($path, 0775, true);

        self::$created[] = $path;

        return str_replace('\\', '/', $path);
    }

    /** Remove every directory this helper created during the test. */
    public static function cleanup(): void
    {
        foreach (self::$created as $path) {
            self::deleteDirectory($path);
        }

        self::$created = [];
    }

    private static function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
