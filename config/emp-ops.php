<?php

declare(strict_types=1);
use App\Services\Geo\Providers\IpApiProvider;
use App\Services\Geo\Providers\IpInfoProvider;
use App\Services\Geo\Providers\MaxMindDbProvider;
use App\Services\Geo\Providers\NullProvider;

/*
|--------------------------------------------------------------------------
| Operations, deployment & location services (WP-24)
|--------------------------------------------------------------------------
| Spec §6.6 (operations & admin health), §6.9 (deployment & install),
| §3.23 (location services) and the remaining §0.1 tech-stack rows.
*/

return [

    /*
     * §3.23 location services (D-10). `providers` is the plugin-extensible
     * GeoIP registry source — merge a key => class entry from a plugin
     * service provider to add a vendor without a core change (§6.1).
     */
    'geo' => [
        'providers' => [
            NullProvider::KEY => NullProvider::class,
            MaxMindDbProvider::KEY => MaxMindDbProvider::class,
            IpApiProvider::KEY => IpApiProvider::class,
            IpInfoProvider::KEY => IpInfoProvider::class,
        ],

        // Seconds before a hosted-provider lookup is abandoned.
        'http_timeout' => 5,

        // Where uploaded .mmdb databases live on the `local` disk.
        'database_directory' => 'geoip',

        // MaxMind auto-update download endpoint (F-3.23-002).
        'maxmind_download_url' => 'https://download.maxmind.com/app/geoip_download',

        // Largest accepted .mmdb upload, kilobytes (GeoLite2-City is ~60 MB).
        'max_upload_kb' => 204800,
    ],

    /*
     * §6.6 operations. Values here are FALLBACKS: the admin Ops settings
     * group (App\Settings\OpsSettings) is the operator-facing authority and
     * is read through SettingsReader::ops().
     */
    'ops' => [
        // Slow-query recorder (F-6.6-003).
        'slow_query_threshold_ms' => 500,
        'slow_query_retention_days' => 7,
        'slow_query_max_sql_length' => 4000,

        // Threshold alerts (F-6.6-005).
        'disk_free_percent_warning' => 15,
        'memory_percent_warning' => 85,
        'database_size_mb_warning' => 10240,

        // Worker registry (F-6.6-008): heartbeat age before a node is stale.
        'worker_stale_seconds' => 120,
        'worker_prune_hours' => 24,

        // Graceful drain (F-6.6-009): cache key the workers poll.
        'drain_cache_key' => 'ops:workers:drain',
        'drain_ttl_minutes' => 60,

        // Error-notification scanner (F-6.6-002).
        'error_levels' => ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'],
        'error_scan_entries' => 200,
    ],

    /*
     * §6.9 deployment & install.
     */
    'install' => [
        // Presence of this file means "installed" — the wizard then 404s.
        'marker' => storage_path('app/installed'),

        // Requirements (spec §6.9 F-6.9-002).
        'min_php' => '8.2.0',
        'required_extensions' => [
            'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'json', 'mbstring',
            'openssl', 'pcre', 'pdo', 'tokenizer', 'xml', 'zip',
        ],
        'recommended_extensions' => ['intl', 'gd', 'redis', 'pcntl', 'posix', 'imap'],
        'writable_paths' => [
            'storage',
            'storage/app',
            'storage/framework',
            'storage/logs',
            'bootstrap/cache',
        ],
        'min_memory_limit_mb' => 256,
    ],

    /*
     * §0.1-016 smart caching layer. `ttl` seconds per cache bucket; 0
     * disables that bucket. Tag support is driver-dependent (array/redis/
     * memcached) — SmartCache degrades to prefixed keys elsewhere.
     */
    'cache' => [
        'enabled' => env('EMP_SMART_CACHE', true),
        'prefix' => 'emp:smart:',
        'ttl' => [
            'dashboard' => 300,
            'reports' => 600,
            'lookups' => 3600,
        ],
    ],

];
