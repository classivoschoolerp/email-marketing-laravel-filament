<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\SecuritySettings;

/**
 * Typed accessors for the `security` settings group (spec §3.25.15).
 *
 * Every accessor keeps the pre-WP-22 config value as its fallback so the
 * consumers (LoginThrottle, the middleware stack, PasswordPolicy, the
 * UploadGuard) behave identically before the settings migration has run.
 */
final readonly class SecuritySettingsReader
{
    public function __construct(private SecuritySettings $settings) {}

    // ------------------------------------------------ F-3.25.15-001 login

    public function loginMaxAttempts(): int
    {
        return max(1, $this->settings->login_max_attempts);
    }

    /** Per-account lockout window in seconds. */
    public function loginLockoutSeconds(): int
    {
        return max(1, $this->settings->login_lockout_minutes) * 60;
    }

    public function loginIpMaxAttempts(): int
    {
        return max(1, $this->settings->login_ip_max_attempts);
    }

    /** IP-wide lockout window in seconds. */
    public function loginIpLockoutSeconds(): int
    {
        return max(1, $this->settings->login_ip_lockout_minutes) * 60;
    }

    // ---------------------------------------------- F-3.25.15-002 sessions

    public function sessionLifetimeMinutes(): int
    {
        return max(1, $this->settings->session_lifetime_minutes);
    }

    /** 0 = idle timeout disabled (D-23). */
    public function idleTimeoutMinutes(): int
    {
        return max(0, $this->settings->session_idle_timeout_minutes);
    }

    // ---------------------------------------------- F-3.25.15-003 passwords

    public function passwordMinLength(): int
    {
        return max(1, $this->settings->password_min_length);
    }

    public function passwordRequiresMixedCase(): bool
    {
        return $this->settings->password_require_mixed_case;
    }

    public function passwordRequiresNumbers(): bool
    {
        return $this->settings->password_require_numbers;
    }

    public function passwordRequiresSymbols(): bool
    {
        return $this->settings->password_require_symbols;
    }

    /** 0 = passwords never expire (D-23). */
    public function passwordExpiryDays(): int
    {
        return max(0, $this->settings->password_expiry_days);
    }

    /** 0 = reuse prevention off (D-23). */
    public function passwordReusePrevention(): int
    {
        return max(0, $this->settings->password_reuse_prevention);
    }

    // ------------------------------------------------ F-3.25.15-004 https

    public function forceHttps(): bool
    {
        return $this->settings->force_https;
    }

    public function hstsEnabled(): bool
    {
        return $this->settings->hsts_enabled;
    }

    public function hstsMaxAge(): int
    {
        return max(0, $this->settings->hsts_max_age);
    }

    public function hstsIncludeSubdomains(): bool
    {
        return $this->settings->hsts_include_subdomains;
    }

    public function hstsPreload(): bool
    {
        return $this->settings->hsts_preload;
    }

    /** Full Strict-Transport-Security header value. */
    public function hstsHeader(): string
    {
        $value = 'max-age='.$this->hstsMaxAge();

        if ($this->hstsIncludeSubdomains()) {
            $value .= '; includeSubDomains';
        }

        if ($this->hstsPreload()) {
            $value .= '; preload';
        }

        return $value;
    }

    // -------------------------------------------- F-3.25.15-005 admin IPs

    /** @return array<int, string> empty = unrestricted */
    public function adminAllowedIps(): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $ip): string => trim((string) $ip),
                $this->settings->admin_allowed_ips,
            ),
            static fn (string $ip): bool => $ip !== '',
        ));
    }

    // --------------------------------------------- F-3.25.15-006 uploads

    /** @return array<int, string> lowercase, dot-free extensions */
    public function uploadAllowedExtensions(): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $ext): string => ltrim(mb_strtolower(trim((string) $ext)), '.'),
                $this->settings->upload_allowed_extensions,
            ),
            static fn (string $ext): bool => $ext !== '',
        )));
    }

    public function uploadMaxSizeMb(): int
    {
        return max(0, $this->settings->upload_max_size_mb);
    }

    public function uploadMaxSizeBytes(): int
    {
        return $this->uploadMaxSizeMb() * 1024 * 1024;
    }

    public function uploadScanEnabled(): bool
    {
        return $this->settings->upload_scan_enabled;
    }

    public function uploadScannerDriver(): string
    {
        $driver = mb_strtolower(trim($this->settings->upload_scanner_driver));

        return $driver === '' ? 'null' : $driver;
    }

    public function uploadScannerHost(): string
    {
        $host = trim($this->settings->upload_scanner_host);

        return $host === '' ? '127.0.0.1' : $host;
    }

    public function uploadScannerPort(): int
    {
        return $this->settings->upload_scanner_port > 0 ? $this->settings->upload_scanner_port : 3310;
    }

    // ------------------------------------------------ F-3.25.15-007 audit

    /** 0 = keep audit rows forever (D-23). */
    public function auditLogRetentionDays(): int
    {
        return max(0, $this->settings->audit_log_retention_days);
    }
}
