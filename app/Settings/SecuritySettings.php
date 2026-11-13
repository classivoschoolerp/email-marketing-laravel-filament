<?php

declare(strict_types=1);

namespace App\Settings;

use App\Console\Commands\LogsPruneCommand;
use App\Http\Middleware\AdminIpAllowlist;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\ForceHttps;
use App\Rules\PasswordPolicy;
use App\Services\Auth\LoginThrottle;
use App\Services\Security\UploadGuard;
use App\Services\Settings\Readers\SecuritySettingsReader;
use Spatie\LaravelSettings\Settings;

/**
 * Security settings (spec §3.25.15, WP-22).
 *
 * Every value here supersedes a pre-existing config default; the readers in
 * {@see SecuritySettingsReader} keep the
 * config fallback so nothing breaks before the settings migration runs.
 *
 * Consumers:
 *  - F-3.25.15-001 → {@see LoginThrottle}
 *  - F-3.25.15-002 → {@see EnforceIdleTimeout}
 *  - F-3.25.15-003 → {@see PasswordPolicy} + password_histories
 *  - F-3.25.15-004 → {@see ForceHttps}
 *  - F-3.25.15-005 → {@see AdminIpAllowlist}
 *  - F-3.25.15-006 → {@see UploadGuard}
 *  - F-3.25.15-007 → {@see LogsPruneCommand}
 */
class SecuritySettings extends Settings
{
    // F-3.25.15-001 — login attempt limits & lockout duration.
    public int $login_max_attempts;

    public int $login_lockout_minutes;

    public int $login_ip_max_attempts;

    public int $login_ip_lockout_minutes;

    // F-3.25.15-002 — session lifetime & idle timeout (minutes; 0 = disabled, D-23).
    public int $session_lifetime_minutes;

    public int $session_idle_timeout_minutes;

    // F-3.25.15-003 — password policy.
    public int $password_min_length;

    public bool $password_require_mixed_case;

    public bool $password_require_numbers;

    public bool $password_require_symbols;

    /** Days after which a password must be changed; 0 = never expires (D-23). */
    public int $password_expiry_days;

    /** Number of previous hashes a new password may not match; 0 = off (D-23). */
    public int $password_reuse_prevention;

    // F-3.25.15-004 — transport security.
    public bool $force_https;

    public bool $hsts_enabled;

    public int $hsts_max_age;

    public bool $hsts_include_subdomains;

    public bool $hsts_preload;

    /**
     * F-3.25.15-005 — admin panel IP allowlist; empty = no restriction.
     *
     * @var array<int, string>
     */
    public array $admin_allowed_ips;

    /**
     * F-3.25.15-006 — upload guard: allowed extensions (lowercase, no dot),
     * hard size cap and the optional virus scanner.
     *
     * @var array<int, string>
     */
    public array $upload_allowed_extensions;

    public int $upload_max_size_mb;

    public bool $upload_scan_enabled;

    /** Scanner driver key: `null` (no-op) or `clamav` (clamd socket). */
    public string $upload_scanner_driver;

    public string $upload_scanner_host;

    public int $upload_scanner_port;

    // F-3.25.15-007 — audit log retention in days; 0 = keep forever (D-23).
    public int $audit_log_retention_days;

    public static function group(): string
    {
        return 'security';
    }
}
