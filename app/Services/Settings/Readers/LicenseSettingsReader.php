<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\LicenseSettings;

/**
 * Typed accessors for the `license` settings group (spec §3.25.12, D-09).
 */
final readonly class LicenseSettingsReader
{
    public function __construct(private LicenseSettings $settings) {}

    public function licenseKey(): ?string
    {
        return $this->settings->license_key;
    }

    /**
     * One of: unknown | valid | invalid | unreachable.
     */
    public function status(): string
    {
        return $this->settings->status;
    }

    public function valid(): bool
    {
        return $this->settings->status === LicenseSettings::STATUS_VALID;
    }

    public function type(): ?string
    {
        return $this->settings->type;
    }

    /** ISO date (Y-m-d); null = perpetual. */
    public function expiresAt(): ?string
    {
        return $this->settings->expires_at;
    }

    /**
     * @return list<string>
     */
    public function allowedDomains(): array
    {
        return $this->settings->allowed_domains;
    }

    public function renewalUrl(): ?string
    {
        return $this->settings->renewal_url;
    }

    public function lastCheckedAt(): ?string
    {
        return $this->settings->last_checked_at;
    }
}
