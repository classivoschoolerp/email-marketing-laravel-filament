<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\SsoSettings;

/**
 * Typed accessors for the `sso` settings group (spec §6.7 P2 rows).
 *
 * A provider is only "configured" when it is enabled AND both halves of its
 * credential pair are present — half a credential is a misconfiguration, and
 * showing a sign-in button that can only fail is worse than showing none.
 *
 * Per-provider credentials resolve settings-first, config-second: see
 * {@see self::credential()}.
 */
final readonly class SsoSettingsReader
{
    public function __construct(private SsoSettings $settings) {}

    public function loginTokenEnabled(): bool
    {
        return $this->settings->login_token_enabled;
    }

    /** Clamped to the config ceiling — settings can never widen the window. */
    public function loginTokenTtlSeconds(): int
    {
        $max = (int) config('emp-sso.login_token.max_ttl_seconds', 300);

        return max(5, min($max, $this->settings->login_token_ttl_seconds));
    }

    public function autoCreateAccounts(): bool
    {
        return $this->settings->auto_create_accounts;
    }

    public function enabled(string $driver): bool
    {
        return (bool) $this->credential($driver, 'enabled', false);
    }

    public function clientId(string $driver): ?string
    {
        $value = $this->credential($driver, 'client_id');

        return $this->blank(is_string($value) ? $value : null);
    }

    public function clientSecret(string $driver): ?string
    {
        $value = $this->credential($driver, 'client_secret');

        return $this->blank(is_string($value) ? $value : null);
    }

    /**
     * A provider's credential, from the settings group when the settings
     * class carries the property and from `config/emp-sso.php` otherwise.
     *
     * The fallback exists so a driver can ship before its three settings
     * fields do (GitHub, F-1.2-011). It is deliberately settings-FIRST: the
     * day `github_enabled` is added to SsoSettings, the admin UI silently
     * becomes the authority with no adapter change and no reader change.
     */
    private function credential(string $driver, string $suffix, mixed $default = null): mixed
    {
        // Driver keys come from the registry and from the route pattern, but
        // this string ends up in a dotted config path, so it is checked here
        // rather than trusted.
        if (preg_match('/^[a-z0-9_-]{2,32}$/', $driver) !== 1) {
            return $default;
        }

        $property = $driver.'_'.$suffix;

        if (property_exists($this->settings, $property)) {
            return $this->settings->{$property};
        }

        return config('emp-sso.social.'.$driver.'.'.$suffix, $default);
    }

    public function microsoftTenant(): string
    {
        return $this->blank($this->settings->microsoft_tenant) ?? 'common';
    }

    /** Enabled AND both credential halves present. */
    public function configured(string $driver): bool
    {
        return $this->enabled($driver)
            && $this->clientId($driver) !== null
            && $this->clientSecret($driver) !== null;
    }

    /**
     * @return list<string> driver keys ready to be offered on a login screen
     */
    public function configuredDrivers(): array
    {
        $drivers = array_map('strval', (array) config('emp-sso.social.drivers', []));

        return array_values(array_filter($drivers, fn (string $d): bool => $this->configured($d)));
    }

    private function blank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
