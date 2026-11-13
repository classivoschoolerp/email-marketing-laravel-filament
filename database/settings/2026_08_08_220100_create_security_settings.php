<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * §3.25.15 security settings (WP-22). Defaults deliberately mirror the
 * pre-existing config values (config/emp.php login.*, config/session.php
 * lifetime, the WP-02 Password::min(8)->mixedCase()->numbers() rule) so
 * seeding the group changes no behaviour anywhere.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('security', function (SettingsBlueprint $blueprint): void {
            // F-3.25.15-001 — mirrors config('emp.login.*') exactly.
            $blueprint->add('login_max_attempts', 5);
            $blueprint->add('login_lockout_minutes', 1);
            $blueprint->add('login_ip_max_attempts', 20);
            $blueprint->add('login_ip_lockout_minutes', 10);

            // F-3.25.15-002 — mirrors config('session.lifetime'); idle off by default.
            $blueprint->add('session_lifetime_minutes', 120);
            $blueprint->add('session_idle_timeout_minutes', 0);

            // F-3.25.15-003 — mirrors the WP-02 password defaults.
            $blueprint->add('password_min_length', 8);
            $blueprint->add('password_require_mixed_case', true);
            $blueprint->add('password_require_numbers', true);
            $blueprint->add('password_require_symbols', false);
            $blueprint->add('password_expiry_days', 0);
            $blueprint->add('password_reuse_prevention', 0);

            // F-3.25.15-004 — off by default (dev/self-hosted http installs).
            $blueprint->add('force_https', false);
            $blueprint->add('hsts_enabled', false);
            $blueprint->add('hsts_max_age', 31536000);
            $blueprint->add('hsts_include_subdomains', false);
            $blueprint->add('hsts_preload', false);

            // F-3.25.15-005 — empty = every IP may reach /admin.
            $blueprint->add('admin_allowed_ips', []);

            // F-3.25.15-006 — upload guard.
            $blueprint->add('upload_allowed_extensions', [
                'csv', 'txt', 'zip', 'html', 'htm', 'png', 'jpg', 'jpeg', 'gif',
                'webp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'json', 'xml',
            ]);
            $blueprint->add('upload_max_size_mb', 20);
            $blueprint->add('upload_scan_enabled', false);
            $blueprint->add('upload_scanner_driver', 'null');
            $blueprint->add('upload_scanner_host', '127.0.0.1');
            $blueprint->add('upload_scanner_port', 3310);

            // F-3.25.15-007 — audit trail retention (login/impersonation/consent/API logs).
            $blueprint->add('audit_log_retention_days', 365);
        });
    }
};
