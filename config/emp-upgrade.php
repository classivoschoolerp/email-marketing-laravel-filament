<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Live upgrade manager (spec §6.2, WP-41)
|--------------------------------------------------------------------------
| DANGER — read docs/upgrades.md before changing anything in this file.
|
| Everything here drives a routine that OVERWRITES THE RUNNING APPLICATION'S
| SOURCE CODE and then runs migrations against the live database. It is the
| single most destructive operation the product exposes over HTTP.
|
| Two invariants hold this together and neither may be weakened:
|
|   1. `target_path` is the ONLY place bytes are written. It defaults to
|      base_path() in production and is INJECTED with a throwaway directory by
|      every test — App\Services\Upgrade\UpgradeRunner refuses outright to run
|      against base_path() while APP_ENV=testing, so a test can never mutate
|      the real installation.
|   2. `protected_paths` is never written, in any mode, for any release. It is
|      what makes "no reinstall, no data loss" (F-6.2-006) true rather than
|      aspirational: plugins, themes, brand assets, backups, uploads and the
|      environment file all survive an upgrade untouched.
*/

return [

    /*
     * Release feed (F-6.2-001). A GET returning JSON:
     *
     *   {
     *     "version": "1.1.0",
     *     "released_at": "2026-08-13T09:00:00Z",
     *     "download_url": "https://…/emp-1.1.0.zip",
     *     "checksum": "<sha256 of the zip, lowercase hex>",
     *     "checksum_algo": "sha256",
     *     "changelog": "### 1.1.0\n- …",   // markdown or plain text
     *     "min_php": "8.2.0",
     *     "requires_core": ">=1.0.0",       // constraint on the CURRENT core
     *     "mandatory": false,
     *     "notes": "…"
     *   }
     *
     * A `channels` object keyed by channel name is also accepted, in which
     * case the `channel` key below selects the branch.
     *
     * Empty endpoint = the upgrade manager reports "no release feed
     * configured" and every write path is closed. That is the default: a
     * self-hosted install must opt in to being told what to run.
     */
    'release' => [
        'endpoint' => env('EMP_UPGRADE_ENDPOINT'),
        'channel' => env('EMP_UPGRADE_CHANNEL', 'stable'),
        'timeout' => (int) env('EMP_UPGRADE_TIMEOUT', 15),

        // Seconds the fetched release document is cached for. The dashboard
        // reads through this cache; "Check now" busts it.
        'cache_ttl' => (int) env('EMP_UPGRADE_CACHE_TTL', 1800),
    ],

    /*
     * Where an upgrade is applied. NULL → base_path(). Tests inject a temp
     * directory here; see the invariant note at the top of this file.
     */
    'target_path' => env('EMP_UPGRADE_TARGET_PATH'),

    /* Scratch space: downloads, staging and rollback snapshots. */
    'work_path' => env('EMP_UPGRADE_WORK_PATH'),

    /*
     * NEVER written by an upgrade (F-6.2-006). Relative to target_path.
     * A release archive that contains one of these paths has that entry
     * SKIPPED and the skip is recorded on the upgrade run, so the operator
     * can see exactly what the package tried to touch.
     */
    'protected_paths' => [
        '.env',
        '.env.backup',
        'storage/app/plugins',
        'storage/app/themes',
        'storage/app/backups',
        'storage/app/public',
        'storage/app/private',
        'storage/app/upgrades',
        'storage/backups',
        'storage/framework',
        'storage/logs',
        'database/database.sqlite',
        'public/storage',
    ],

    /*
     * The operator must type this EXACTLY to arm the upgrade button
     * (F-6.2-002 guard). Deliberately a config constant and not a translated
     * string: a phrase that changes with the admin's locale is a phrase that
     * can be typed by accident in the wrong language.
     */
    'confirmation_phrase' => env('EMP_UPGRADE_CONFIRM_PHRASE', 'UPGRADE'),

    /*
     * Pre-flight gates (F-6.2-003). Every one of these BLOCKS the upgrade
     * when it fails — there is no override flag, because every "force" flag
     * ever shipped has eventually been used on a full disk.
     */
    'preflight' => [
        // Free disk required beyond the download itself, megabytes.
        'min_free_disk_mb' => (int) env('EMP_UPGRADE_MIN_DISK_MB', 512),

        // Floor applied when the release document does not declare `min_php`.
        'min_php_version' => env('EMP_UPGRADE_MIN_PHP', '8.2.0'),
    ],

    'limits' => [
        // Hard cap on the downloaded archive (megabytes).
        'max_download_mb' => (int) env('EMP_UPGRADE_MAX_DOWNLOAD_MB', 250),

        // Zip-bomb guards, same shape as the plugin archive gate (WP-36).
        'max_entries' => (int) env('EMP_UPGRADE_MAX_ENTRIES', 30000),
        'max_uncompressed_mb' => (int) env('EMP_UPGRADE_MAX_UNCOMPRESSED_MB', 750),
        'max_compression_ratio' => (int) env('EMP_UPGRADE_MAX_RATIO', 250),
        'max_entry_path_length' => 220,
    ],

    /*
     * Extension policy INSIDE the release archive. A core release IS
     * executable PHP, so there is deliberately no allow-list — filtering one
     * would silently drop files and leave a half-written install, which is
     * worse than not upgrading. The block-list rejects the WHOLE package: a
     * PHP application release has no business shipping native binaries.
     */
    'blocked_file_extensions' => [
        'phar', 'exe', 'com', 'msi', 'scr', 'vbs', 'dll', 'so', 'dylib',
        'jar', 'bin', 'app', 'deb', 'rpm', 'dmg', 'pkg',
    ],

    /*
     * Optional metadata file at the archive root. When present its `version`
     * MUST match the version the release feed advertised, otherwise the
     * package is rejected — that mismatch is either a mis-published release
     * or a swapped download.
     */
    'manifest_file' => 'release.json',

    /* Maintenance mode around the destructive steps (F-6.2-005). */
    'maintenance' => [
        'enabled' => (bool) env('EMP_UPGRADE_MAINTENANCE', true),
        'retry_after' => (int) env('EMP_UPGRADE_MAINTENANCE_RETRY', 60),
    ],

    /*
     * Cache-clearing performed after a successful apply. `filament:assets` is
     * NOT optional — D-87: skipping it renders both panels unstyled.
     */
    'post_upgrade_commands' => [
        'config:clear',
        'cache:clear',
        'view:clear',
        'route:clear',
        'filament:assets',
    ],

    /* F-6.2-008 — email admins when a newer release appears. */
    'notify' => [
        'enabled' => (bool) env('EMP_UPGRADE_NOTIFY', false),

        // Explicit recipient; empty = every admin holding `system.upgrade`
        // (plus Super Admins), resolved at send time.
        'to' => env('EMP_UPGRADE_NOTIFY_TO'),
    ],

    /* Upgrade history retention in days; 0 = keep forever (D-23). */
    'history_retention_days' => (int) env('EMP_UPGRADE_HISTORY_DAYS', 0),
];
