<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Location services settings (spec §3.23, D-10). Chooses the active GeoIP
 * provider, stores its credentials (encrypted) and the uploaded MaxMind
 * database path, and gates the geo enrichment of tracking hits / login logs.
 */
class LocationSettings extends Settings
{
    public const PROVIDER_NULL = 'null';

    public const PROVIDER_MAXMIND = 'maxmind';

    public const PROVIDER_IP_API = 'ip-api';

    public const PROVIDER_IPINFO = 'ipinfo';

    /** @var list<string> */
    public const PROVIDERS = [
        self::PROVIDER_NULL,
        self::PROVIDER_MAXMIND,
        self::PROVIDER_IP_API,
        self::PROVIDER_IPINFO,
    ];

    /** MaxMind editions the updater knows how to download (F-3.23-002). */
    public const MAXMIND_EDITIONS = [
        'GeoLite2-City',
        'GeoLite2-Country',
        'GeoIP2-City',
        'GeoIP2-Country',
    ];

    // F-3.23-001
    public string $provider;

    // F-3.23-002 — uploaded database, relative to the `local` disk.
    public ?string $maxmind_database_path;

    public ?string $maxmind_database_uploaded_at;

    public string $maxmind_edition;

    public ?string $maxmind_license_key;

    public bool $maxmind_auto_update;

    public ?string $maxmind_updated_at;

    // Hosted providers
    public ?string $ipinfo_token;

    public ?string $ipapi_key;

    /** Lookup cache lifetime in minutes; 0 = no caching (D-23). */
    public int $cache_ttl_minutes;

    /** Geo enrichment switches (WP-24 wiring). */
    public bool $enrich_tracking;

    public bool $enrich_login_logs;

    public static function group(): string
    {
        return 'location';
    }

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return [
            'maxmind_license_key',
            'ipinfo_token',
            'ipapi_key',
        ];
    }
}
