<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * SSO & external authentication settings (spec §6.7 P2 rows, WP-38).
 *
 * Covers the one-time login token API (F-6.7-001) and social login
 * (F-6.7-003).
 *
 * SAML / OIDC (F-6.7-002) shipped in WP-46 and lives in
 * {@see \App\Settings\EnterpriseSsoSettings} (`enterprise_sso` group), NOT
 * here — this class was frozen when that work started, and the split turned
 * out to be the right shape anyway: consumer sign-in buttons and corporate
 * federation are different features with different operators.
 *
 * Every client secret is in {@see self::encrypted()}: spatie encrypts those
 * values at rest with the app key, the same treatment D-48 gives delivery
 * server credentials.
 */
class SsoSettings extends Settings
{
    // ---- One-time login token API (F-6.7-001) -------------------------

    public bool $login_token_enabled;

    /** Seconds a token stays redeemable; clamped to config max_ttl_seconds. */
    public int $login_token_ttl_seconds;

    // ---- Social login (F-6.7-003) -------------------------------------

    public bool $google_enabled;

    public ?string $google_client_id;

    public ?string $google_client_secret;

    public bool $microsoft_enabled;

    public ?string $microsoft_client_id;

    public ?string $microsoft_client_secret;

    /** Microsoft tenant ('common', 'organizations' or a directory id). */
    public string $microsoft_tenant;

    /**
     * Create a customer on first social sign-in. When false, a social
     * identity that matches no existing account is rejected — the operator's
     * choice between "open signup" and "link-only".
     */
    public bool $auto_create_accounts;

    public static function group(): string
    {
        return 'sso';
    }

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return [
            'google_client_secret',
            'microsoft_client_secret',
        ];
    }
}
