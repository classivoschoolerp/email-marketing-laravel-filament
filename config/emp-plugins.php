<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Plugin system (spec §6.1 + §3.20, WP-36)
|--------------------------------------------------------------------------
| SECURITY NOTE — read docs/plugins.md before changing anything here.
|
| A plugin is arbitrary PHP that this application loads and executes with the
| privileges of the web/queue user. Everything in this file is a *containment*
| control (what may enter the system and how big it may be); none of it is a
| sandbox. The only real trust boundary is the `plugins.manage` permission.
*/

return [

    /*
     * Where extracted plugin code lives. Deliberately under storage/ so it
     * survives a core upgrade (D-94): `git pull` / `composer install` /
     * an artifact deploy never touch storage/app. It is also outside the
     * document root, so uploaded PHP can never be requested over HTTP.
     */
    'path' => storage_path('app/plugins'),

    /* Root PSR-4 namespace segment plugins are expected to publish under. */
    'namespace_hint' => 'EmpPlugin',

    'limits' => [
        // Hard cap on the uploaded .zip itself (megabytes).
        'max_upload_mb' => (int) env('EMP_PLUGIN_MAX_UPLOAD_MB', 20),

        // Zip-bomb guards: entry count and total *uncompressed* payload.
        'max_entries' => (int) env('EMP_PLUGIN_MAX_ENTRIES', 2000),
        'max_uncompressed_mb' => (int) env('EMP_PLUGIN_MAX_UNCOMPRESSED_MB', 100),

        // Reject any single entry whose compression ratio exceeds this.
        'max_compression_ratio' => (int) env('EMP_PLUGIN_MAX_RATIO', 200),

        // Longest accepted path inside the archive.
        'max_entry_path_length' => 180,
    ],

    /*
     * Slugs an operator may never install: they would collide with core
     * concepts, core paths or the reserved namespace roots below.
     */
    'reserved_slugs' => [
        'app', 'core', 'emp', 'admin', 'customer', 'system', 'laravel',
        'filament', 'livewire', 'plugin', 'plugins', 'vendor', 'storage',
        'public', 'config', 'database', 'resources', 'routes', 'tests',
        'bootstrap', 'lang', 'node-modules', 'default', 'new', 'edit',
    ],

    /*
     * PSR-4 prefixes a plugin may never claim. Without this a plugin could
     * publish `App\Sending\DriverRegistry` and — depending on autoloader
     * order — shadow a core class. Compared case-insensitively on the first
     * namespace segment.
     */
    'reserved_namespaces' => [
        'App', 'Illuminate', 'Laravel', 'Filament', 'Livewire', 'Symfony',
        'Tests', 'Database', 'Spatie', 'Composer', 'PHPUnit', 'Pest', 'Carbon',
    ],

    /*
     * File-extension policy INSIDE the archive. Anything not on the allow
     * list is skipped during extraction (never written to disk); anything on
     * the block list rejects the whole package. `php` is allowed on purpose —
     * that is the entire point of a plugin — which is exactly why the trust
     * model in docs/plugins.md has to be read before enabling uploads.
     */
    'allowed_file_extensions' => [
        'php', 'json', 'md', 'txt', 'yml', 'yaml', 'xml', 'csv',
        'css', 'js', 'blade', 'html', 'htm', 'svg',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
    ],

    'blocked_file_extensions' => [
        'phar', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht',
        'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'cgi', 'pl', 'py',
        'jsp', 'asp', 'aspx', 'dll', 'so', 'dylib', 'msi', 'scr', 'vbs',
        'ps1', 'jar', 'htaccess', 'ini', 'sql',
    ],

    /*
     * Manifest file name, and the class every entry class must extend.
     */
    'manifest_file' => 'plugin.json',

    'runtime' => [
        // Master kill-switch: false stops the booter from loading ANY plugin
        // code (recovery mode for an operator locked out by a bad plugin).
        'boot' => (bool) env('EMP_PLUGINS_BOOT', true),
    ],

    /*
     * AI engine adapters (F-6.1-009). Core ships the hooking point only —
     * spec §2.15.8: "AI ships as a plugin, core only exposes hooking points".
     * Plugins merge key => class entries here or call
     * AiEngineRegistry::register() from their service provider.
     */
    'ai_engines' => [],

    /*
    |--------------------------------------------------------------------------
    | Marketplace / repository listing (spec §6.1 F-6.1-016, WP-46)
    |--------------------------------------------------------------------------
    | A repository is a single JSON index at `base_url` listing packages that
    | can be installed from the admin UI. The document format is specified in
    | docs/plugins.md §Marketplace.
    |
    | NO OFFICIAL REPOSITORY IS HOSTED YET, so `base_url` ships EMPTY and the
    | marketplace screen says "not configured" rather than "no plugins found".
    | The distinction matters: an empty list reads as "this repository has
    | nothing", which is a claim we are in no position to make.
    |
    | D-103 STILL APPLIES IN FULL. The sha256 in the index proves the bytes
    | arrived intact; it says nothing whatsoever about whether the code is
    | safe. Marketplace packages go through exactly the same PluginArchive /
    | PluginInstaller gate as an upload, and the same acknowledgement is
    | required before install.
    */
    'marketplace' => [

        'enabled' => (bool) env('EMP_PLUGIN_MARKETPLACE_ENABLED', true),

        /* Repository root, e.g. https://plugins.example.com. Empty = off. */
        'base_url' => rtrim((string) env('EMP_PLUGIN_MARKETPLACE_URL', ''), '/'),

        /* Index document, resolved relative to base_url. */
        'index_path' => (string) env('EMP_PLUGIN_MARKETPLACE_INDEX', 'index.json'),

        /*
         * How long a successfully fetched index is reused (Laravel Cache,
         * D-04). A repository outage is therefore invisible for up to this
         * long, which is the intended trade: browsing plugins must not put a
         * third-party host in the critical path of an admin page load.
         */
        'cache_ttl_minutes' => (int) env('EMP_PLUGIN_MARKETPLACE_TTL', 60),

        'cache_key' => 'emp.plugins.marketplace.index',

        'timeout_seconds' => (int) env('EMP_PLUGIN_MARKETPLACE_TIMEOUT', 15),

        'download_timeout_seconds' => (int) env('EMP_PLUGIN_MARKETPLACE_DOWNLOAD_TIMEOUT', 120),

        /*
         * Hosts a `download_url` may point at. EMPTY = any host, because a
         * repository legitimately serves its packages from a CDN. Pin it when
         * you know where your packages live: it removes the repository's
         * ability to redirect an install at an arbitrary server.
         *
         * The URL scheme is always restricted to http/https regardless — a
         * `file://` download_url would otherwise let an index read the disk.
         */
        'allowed_download_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('EMP_PLUGIN_MARKETPLACE_HOSTS', ''))
        ))),
    ],
];
