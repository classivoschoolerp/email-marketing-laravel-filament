<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\CdnSettings;

/**
 * Typed accessors for the `cdn` settings group (spec §3.25.9).
 */
final readonly class CdnSettingsReader
{
    public function __construct(private CdnSettings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->enabled;
    }

    public function baseUrl(): ?string
    {
        return $this->settings->base_url;
    }

    public function subdomain(): ?string
    {
        return $this->settings->subdomain;
    }

    public function useForAssets(): bool
    {
        return $this->settings->use_for_assets;
    }

    public function useForEmailImages(): bool
    {
        return $this->settings->use_for_email_images;
    }

    /**
     * True when CDN rewriting is operational (enabled + base URL configured).
     */
    public function active(): bool
    {
        return $this->settings->enabled
            && $this->settings->base_url !== null
            && $this->settings->base_url !== '';
    }

    /**
     * Absolute URL for a public asset path: served from the CDN when active,
     * from the application host otherwise.
     */
    public function cdnUrl(string $path): string
    {
        $path = ltrim($path, '/');

        if ($this->active()) {
            return rtrim((string) $this->settings->base_url, '/').'/'.$path;
        }

        return asset($path);
    }

    public function provider(): string
    {
        return $this->settings->provider;
    }

    public function s3Bucket(): ?string
    {
        return $this->settings->s3_bucket;
    }

    public function s3Region(): ?string
    {
        return $this->settings->s3_region;
    }

    public function s3AccessKeyId(): ?string
    {
        return $this->settings->s3_access_key_id;
    }

    public function s3SecretAccessKey(): ?string
    {
        return $this->settings->s3_secret_access_key;
    }

    public function cloudfrontDistributionId(): ?string
    {
        return $this->settings->cloudfront_distribution_id;
    }

    public function bunnyStorageZone(): ?string
    {
        return $this->settings->bunny_storage_zone;
    }

    public function bunnyApiKey(): ?string
    {
        return $this->settings->bunny_api_key;
    }

    public function bunnyPullZone(): ?string
    {
        return $this->settings->bunny_pull_zone;
    }
}
