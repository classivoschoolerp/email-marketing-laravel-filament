<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Themes & white-label (spec §6.3 + §3.21 + §4.7, WP-38)
|--------------------------------------------------------------------------
| SECURITY NOTE — a theme package is deliberately NOT a plugin.
|
| A plugin (§6.1, config/emp-themes.php's sibling config/emp-plugins.php) is
| arbitrary PHP that this application executes. A THEME is presentation only:
| stylesheets, images and fonts. Nothing in an uploaded theme is ever
| included, required, evaluated or served as PHP — the installer refuses any
| archive that so much as contains an executable or scriptable extension, and
| the renderer only ever `file_get_contents()`es the manifest-declared
| stylesheet and echoes it inside a <style> tag.
|
| That is why `themes.manage` is a lower-privilege permission than
| `plugins.manage`: the worst a malicious theme can do is deface the UI of the
| area it is activated on, which is the same power the §3.25.8 custom-CSS box
| has always had.
*/

return [

    /*
     * Where extracted theme packages live. Under storage/ for the same reason
     * plugins are (D-94): a core upgrade — `git pull`, `composer install`, an
     * artifact deploy — never touches storage/app, so a custom theme survives
     * it (F-6.3-005). Outside the document root as well, so nothing inside a
     * package can be requested directly over HTTP.
     */
    'path' => storage_path('app/themes'),

    /* Manifest file every package must carry at its root. */
    'manifest_file' => 'theme.json',

    'limits' => [
        'max_upload_mb' => (int) env('EMP_THEME_MAX_UPLOAD_MB', 10),
        'max_entries' => (int) env('EMP_THEME_MAX_ENTRIES', 500),
        'max_uncompressed_mb' => (int) env('EMP_THEME_MAX_UNCOMPRESSED_MB', 40),
        'max_compression_ratio' => (int) env('EMP_THEME_MAX_RATIO', 200),
        'max_entry_path_length' => 180,
        // Hard cap on the stylesheet actually injected into a page.
        'max_stylesheet_kb' => (int) env('EMP_THEME_MAX_STYLESHEET_KB', 512),
    ],

    /*
     * Slugs an operator may never upload: they would shadow a built-in theme
     * or collide with a reserved directory name.
     */
    'reserved_slugs' => [
        'default', 'dark', 'light', 'red', 'blue', 'green', 'pink', 'brown',
        'classic', 'modern', 'core', 'app', 'admin', 'customer', 'frontend',
        'backend', 'system', 'new', 'edit', 'create', 'vendor', 'public',
    ],

    /*
     * File-extension policy INSIDE a theme archive.
     *
     * `allowed` is what gets written to disk. `blocked` rejects the WHOLE
     * package rather than skipping the entry, because an archive that tries
     * to ship one is not an archive we want half-installed.
     *
     * `js` is on the BLOCK list on purpose. A theme is a palette, not a
     * behaviour: an operator who wants scripting has the §3.25.8 custom-JS
     * box, which is an explicit, auditable, per-area decision rather than
     * something smuggled in with a colour scheme.
     */
    'allowed_file_extensions' => [
        'css', 'json', 'md', 'txt',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'avif',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
    ],

    'blocked_file_extensions' => [
        'php', 'phar', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps',
        'js', 'mjs', 'cjs', 'ts', 'jsx', 'vue',
        'exe', 'dll', 'so', 'dylib', 'bat', 'cmd', 'com', 'msi',
        'sh', 'bash', 'zsh', 'ps1', 'py', 'rb', 'pl', 'cgi', 'jar', 'war',
        'htaccess', 'htpasswd', 'ini', 'env', 'blade',
    ],

    /*
     * Multi-domain (spec §6.4). A brand answers on its own host only; the
     * fence is the WP-34 LandingPageUrl::servableOnHost() pattern applied to
     * CMS content — see App\Services\Branding\BrandResolver.
     */
    'domains' => [
        // Port used when probing a brand host for its TLS certificate.
        'ssl_probe_port' => (int) env('EMP_DOMAIN_SSL_PORT', 443),
        'ssl_probe_timeout' => (int) env('EMP_DOMAIN_SSL_TIMEOUT', 5),
        // Warn this many days before a certificate expires.
        'ssl_expiry_warning_days' => (int) env('EMP_DOMAIN_SSL_WARN_DAYS', 14),
    ],

];
