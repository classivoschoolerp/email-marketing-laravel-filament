<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Two-factor authentication settings (spec §3.25.6).
 *
 * Enforcement of the force-toggles lives in
 * {@see \App\Http\Middleware\Enforce2fa}; the TOTP engine parameters are
 * consumed by {@see \App\Services\Auth\TwoFactorService}, replacing the
 * config('emp.twofa') stub values from WP-02.
 */
class TwoFactorSettings extends Settings
{
    // System-wide switch (F-3.25.6-001)
    public bool $enabled;

    // Force toggles (F-3.25.6-002)
    public bool $force_admins;

    public bool $force_customers;

    /** TOTP issuer shown in authenticator apps; null falls back to app name (F-3.25.6-003). */
    public ?string $issuer;

    /** TOTP verification window in 30-second periods either side (F-3.25.6-003). */
    public int $code_validity_window;

    /** Number of single-use recovery codes generated on enrolment (F-3.25.6-003). */
    public int $recovery_code_count;

    public static function group(): string
    {
        return 'twofa';
    }
}
