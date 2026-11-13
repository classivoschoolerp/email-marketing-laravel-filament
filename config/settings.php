<?php

declare(strict_types=1);

use App\Settings\ApiSettings;
use App\Settings\BlacklistSettings;
use App\Settings\CampaignSettings;
use App\Settings\CdnSettings;
use App\Settings\CommonSettings;
use App\Settings\CronSettings;
use App\Settings\CustomerSettings;
use App\Settings\DkimSettings;
use App\Settings\ImportExportSettings;
use App\Settings\LicenseSettings;
use App\Settings\SkinSettings;
use App\Settings\SocialSettings;
use App\Settings\TwoFactorSettings;
use Spatie\LaravelSettings\SettingsCasts\DateTimeInterfaceCast;
use Spatie\LaravelSettings\SettingsCasts\DateTimeZoneCast;
use Spatie\LaravelSettings\SettingsRepositories\DatabaseSettingsRepository;
use Spatie\LaravelSettings\SettingsRepositories\RedisSettingsRepository;

return [

    /*
     * Each settings class used in the application must be registered here.
     * (Auto-discovery below also finds them; the container de-duplicates.)
     */
    'settings' => [
        CommonSettings::class,
        ImportExportSettings::class,
        CampaignSettings::class,
        CustomerSettings::class,
        BlacklistSettings::class,
        TwoFactorSettings::class,
        ApiSettings::class,
        SkinSettings::class,
        CdnSettings::class,
        DkimSettings::class,
        CronSettings::class,
        LicenseSettings::class,
        SocialSettings::class,
    ],

    /*
     * The path where the settings classes will be created.
     */
    'setting_class_path' => app_path('Settings'),

    /*
     * In these directories settings migrations will be stored and ran when migrating.
     * Registered here so `php artisan migrate` — and therefore RefreshDatabase in
     * tests — picks the settings migrations up automatically.
     */
    'migrations_paths' => [
        database_path('settings'),
    ],

    /*
     * When no repository was set for a settings class the following repository
     * will be used for loading and saving settings.
     */
    'default_repository' => 'database',

    /*
     * Settings will be stored and loaded from these repositories.
     */
    'repositories' => [
        'database' => [
            'type' => DatabaseSettingsRepository::class,
            'model' => null,
            'table' => null,
            'connection' => null,
        ],
        'redis' => [
            'type' => RedisSettingsRepository::class,
            'connection' => null,
            'prefix' => null,
        ],
    ],

    /*
     * The encoder and decoder will determine how settings are stored and
     * retrieved in the database. By default, `json_encode` and `json_decode`
     * are used.
     */
    'encoder' => null,
    'decoder' => null,

    /*
     * The contents of settings classes can be cached through your application,
     * settings will be stored within a provided Laravel store and can have an
     * additional prefix.
     */
    'cache' => [
        'enabled' => (bool) env('SETTINGS_CACHE_ENABLED', false),
        'store' => null,
        'prefix' => null,
        'ttl' => null,

        /*
         * When enabled, uses Laravel's memoized cache driver (requires Laravel 12.9+)
         * to keep resolved values in memory during a single request.
         */
        'memo' => env('SETTINGS_CACHE_MEMO', false),
    ],

    /*
     * These global casts will be automatically used whenever a property within
     * your settings class isn't a default PHP type.
     */
    'global_casts' => [
        DateTimeInterface::class => DateTimeInterfaceCast::class,
        DateTimeZone::class => DateTimeZoneCast::class,
    ],

    /*
     * The package will look for settings in these paths and automatically
     * register them.
     */
    'auto_discover_settings' => [
        app_path('Settings'),
    ],

    /*
     * Automatically discovered settings classes can be cached, so they don't
     * need to be searched each time the application boots up.
     */
    'discovered_settings_cache_path' => base_path('bootstrap/cache'),
];
