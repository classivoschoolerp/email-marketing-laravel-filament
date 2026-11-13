<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\TwoFactorSettings;

/**
 * Typed accessors for the `twofa` settings group (spec §3.25.6).
 */
final readonly class TwoFactorSettingsReader
{
    public function __construct(private TwoFactorSettings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->enabled;
    }

    public function forceAdmins(): bool
    {
        return $this->settings->enabled && $this->settings->force_admins;
    }

    public function forceCustomers(): bool
    {
        return $this->settings->enabled && $this->settings->force_customers;
    }

    /**
     * Issuer shown in authenticator apps; falls back to the app name.
     */
    public function issuer(): string
    {
        return $this->settings->issuer ?? (string) config('app.name');
    }

    /**
     * TOTP verification window in 30-second periods either side.
     */
    public function codeValidityWindow(): int
    {
        return max(0, $this->settings->code_validity_window);
    }

    public function recoveryCodeCount(): int
    {
        return max(1, $this->settings->recovery_code_count);
    }
}
