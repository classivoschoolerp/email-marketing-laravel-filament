<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\SecuritySettingsPage;
use App\Models\Customer;
use App\Models\User;
use App\Services\Settings\SettingsReader;
use App\Settings\SecuritySettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * WP-22 — §3.25.15 security settings page (F-3.25.15-001 … 007).
 */

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders the security settings page for an admin', function (): void {
    $this->get(SecuritySettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.security.title'));
});

it('denies the customer guard access to the security settings page', function (): void {
    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get(SecuritySettingsPage::getUrl(panel: 'admin'))->assertRedirect();
});

// The seeded defaults must mirror the pre-WP-22 config values exactly, so
// enabling the settings group changes nothing on an existing install.
it('seeds defaults that mirror the pre-existing config values', function (): void {
    $security = SettingsReader::security();

    expect($security->loginMaxAttempts())->toBe((int) config('emp.login.max_attempts'))
        ->and($security->loginLockoutSeconds())->toBe((int) config('emp.login.decay_seconds'))
        ->and($security->loginIpMaxAttempts())->toBe((int) config('emp.login.ip_max_attempts'))
        ->and($security->loginIpLockoutSeconds())->toBe((int) config('emp.login.ip_decay_seconds'))
        ->and($security->sessionLifetimeMinutes())->toBe(120)
        ->and($security->idleTimeoutMinutes())->toBe(0)
        ->and($security->passwordMinLength())->toBe(8)
        ->and($security->passwordRequiresMixedCase())->toBeTrue()
        ->and($security->passwordRequiresNumbers())->toBeTrue()
        ->and($security->passwordRequiresSymbols())->toBeFalse()
        ->and($security->passwordExpiryDays())->toBe(0)
        ->and($security->passwordReusePrevention())->toBe(0)
        ->and($security->forceHttps())->toBeFalse()
        ->and($security->hstsEnabled())->toBeFalse()
        ->and($security->adminAllowedIps())->toBe([])
        ->and($security->uploadScanEnabled())->toBeFalse()
        ->and($security->uploadMaxSizeMb())->toBe(20)
        ->and($security->auditLogRetentionDays())->toBe(365)
        ->and($security->uploadAllowedExtensions())->toContain('zip', 'csv');
});

// F-3.25.15-001 … 007
it('persists every security setting', function (): void {
    Livewire::test(SecuritySettingsPage::class)
        ->fillForm([
            'login_max_attempts' => 3,
            'login_lockout_minutes' => 15,
            'login_ip_max_attempts' => 30,
            'login_ip_lockout_minutes' => 45,
            'session_lifetime_minutes' => 60,
            'session_idle_timeout_minutes' => 10,
            'password_min_length' => 12,
            'password_require_mixed_case' => true,
            'password_require_numbers' => true,
            'password_require_symbols' => true,
            'password_expiry_days' => 90,
            'password_reuse_prevention' => 3,
            'force_https' => true,
            'hsts_enabled' => true,
            'hsts_max_age' => 63072000,
            'hsts_include_subdomains' => true,
            'hsts_preload' => true,
            'admin_allowed_ips' => ['203.0.113.4', '198.51.100.0/24'],
            'upload_allowed_extensions' => ['csv', 'zip'],
            'upload_max_size_mb' => 5,
            'upload_scan_enabled' => true,
            'upload_scanner_driver' => 'clamav',
            'upload_scanner_host' => 'clamd.internal',
            'upload_scanner_port' => 3311,
            'audit_log_retention_days' => 30,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(SecuritySettings::class)->refresh();

    expect($settings->login_max_attempts)->toBe(3)
        ->and($settings->login_lockout_minutes)->toBe(15)
        ->and($settings->session_idle_timeout_minutes)->toBe(10)
        ->and($settings->password_min_length)->toBe(12)
        ->and($settings->password_require_symbols)->toBeTrue()
        ->and($settings->password_expiry_days)->toBe(90)
        ->and($settings->password_reuse_prevention)->toBe(3)
        ->and($settings->force_https)->toBeTrue()
        ->and($settings->hsts_enabled)->toBeTrue()
        ->and($settings->admin_allowed_ips)->toBe(['203.0.113.4', '198.51.100.0/24'])
        ->and($settings->upload_allowed_extensions)->toBe(['csv', 'zip'])
        ->and($settings->upload_scanner_driver)->toBe('clamav')
        ->and($settings->upload_scanner_port)->toBe(3311)
        ->and($settings->audit_log_retention_days)->toBe(30);

    // The readers derive their computed forms from the saved values.
    expect(SettingsReader::security()->loginLockoutSeconds())->toBe(900)
        ->and(SettingsReader::security()->hstsHeader())
        ->toBe('max-age=63072000; includeSubDomains; preload');
});

it('rejects out-of-range security numbers', function (): void {
    Livewire::test(SecuritySettingsPage::class)
        ->fillForm([
            'login_max_attempts' => 0,
            'password_min_length' => 2,
            'upload_scanner_port' => 0,
            'audit_log_retention_days' => -1,
        ])
        ->call('save')
        ->assertHasFormErrors([
            'login_max_attempts',
            'password_min_length',
            'upload_scanner_port',
            'audit_log_retention_days',
        ]);
});
