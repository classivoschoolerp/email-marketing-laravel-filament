<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * CDN settings (spec §3.25.9).
 */
class CdnSettings extends Settings
{
    public const PROVIDER_CUSTOM = 'custom';

    public const PROVIDER_S3_CLOUDFRONT = 's3-cloudfront';

    public const PROVIDER_BUNNY = 'bunny';

    /** @var list<string> */
    public const PROVIDERS = [
        self::PROVIDER_CUSTOM,
        self::PROVIDER_S3_CLOUDFRONT,
        self::PROVIDER_BUNNY,
    ];

    // F-3.25.9-001
    public bool $enabled;

    /** Full CDN base URL, e.g. https://cdn.example.com */
    public ?string $base_url;

    /** Optional CDN subdomain (informational, e.g. "cdn"). */
    public ?string $subdomain;

    public bool $use_for_assets;

    public bool $use_for_email_images;

    // Provider-specific configuration (F-3.25.9-002)

    /** One of: custom | s3-cloudfront | bunny. */
    public string $provider;

    public ?string $s3_bucket;

    public ?string $s3_region;

    public ?string $s3_access_key_id;

    public ?string $s3_secret_access_key;

    public ?string $cloudfront_distribution_id;

    public ?string $bunny_storage_zone;

    public ?string $bunny_api_key;

    public ?string $bunny_pull_zone;

    public static function group(): string
    {
        return 'cdn';
    }

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return [
            's3_secret_access_key',
            'bunny_api_key',
        ];
    }
}
