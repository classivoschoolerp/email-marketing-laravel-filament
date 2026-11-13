<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\LocationSettings;

/**
 * Typed accessors for the `location` settings group (spec §3.23, D-10).
 */
final readonly class LocationSettingsReader
{
    public function __construct(private LocationSettings $settings) {}

    public function provider(): string
    {
        return $this->settings->provider;
    }

    public function enabled(): bool
    {
        return $this->settings->provider !== LocationSettings::PROVIDER_NULL;
    }

    public function maxmindDatabasePath(): ?string
    {
        return $this->settings->maxmind_database_path;
    }

    public function maxmindDatabaseUploadedAt(): ?string
    {
        return $this->settings->maxmind_database_uploaded_at;
    }

    public function maxmindEdition(): string
    {
        return $this->settings->maxmind_edition;
    }

    public function maxmindLicenseKey(): ?string
    {
        return $this->settings->maxmind_license_key;
    }

    public function maxmindAutoUpdate(): bool
    {
        return $this->settings->maxmind_auto_update;
    }

    public function maxmindUpdatedAt(): ?string
    {
        return $this->settings->maxmind_updated_at;
    }

    public function ipinfoToken(): ?string
    {
        return $this->settings->ipinfo_token;
    }

    public function ipapiKey(): ?string
    {
        return $this->settings->ipapi_key;
    }

    public function cacheTtlMinutes(): int
    {
        return max(0, $this->settings->cache_ttl_minutes);
    }

    public function enrichTracking(): bool
    {
        return $this->settings->enrich_tracking;
    }

    public function enrichLoginLogs(): bool
    {
        return $this->settings->enrich_login_logs;
    }
}
