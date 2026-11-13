<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\SecuritySettingsPage;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Models\Customer;
use App\Models\User;
use App\Settings\SecuritySettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

/*
 * WP-22 — F-3.25.15-002: session lifetime + idle timeout, enforced on BOTH
 * panels by EnforceIdleTimeout (registered from ComplianceServiceProvider).
 */

it('registers the idle-timeout middleware on both panels', function (): void {
    foreach (['admin', 'customer'] as $panelId) {
        expect(Filament::getPanel($panelId)->getAuthMiddleware())
            ->toContain(EnforceIdleTimeout::class);
    }
});

it('applies the session lifetime setting at request time', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->session_lifetime_minutes = 45;
    $settings->save();

    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->get(SecuritySettingsPage::getUrl(panel: 'admin'))->assertOk();

    expect((int) config('session.lifetime'))->toBe(45);
});

it('keeps an admin signed in while activity stays inside the idle window', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->session_idle_timeout_minutes = 30;
    $settings->save();

    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->get(SecuritySettingsPage::getUrl(panel: 'admin'))->assertOk();

    // Ten minutes ago — inside the 30 minute window.
    session([EnforceIdleTimeout::SESSION_KEY => time() - 600]);

    $this->get(SecuritySettingsPage::getUrl(panel: 'admin'))->assertOk();

    expect(Auth::guard('admin')->check())->toBeTrue();
});

// F-3.25.15-002 — the enforcement itself
it('signs an idle admin out and redirects to the panel login', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->session_idle_timeout_minutes = 15;
    $settings->save();

    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->get(SecuritySettingsPage::getUrl(panel: 'admin'))->assertOk();

    // Last activity an hour ago — well past the 15 minute timeout.
    session([EnforceIdleTimeout::SESSION_KEY => time() - 3600]);

    $this->get(SecuritySettingsPage::getUrl(panel: 'admin'))
        ->assertRedirect('/admin/login');

    expect(Auth::guard('admin')->check())->toBeFalse();
});

it('signs an idle customer out of the customer panel', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->session_idle_timeout_minutes = 5;
    $settings->save();

    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    $this->get('/customer')->assertSuccessful();

    session([EnforceIdleTimeout::SESSION_KEY => time() - 3600]);

    $this->get('/customer')->assertRedirect('/customer/login');

    expect(Auth::guard('customer')->check())->toBeFalse();
});

// D-23: 0 disables the timeout entirely.
it('never times a session out when the idle timeout is zero', function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->get(SecuritySettingsPage::getUrl(panel: 'admin'))->assertOk();

    session([EnforceIdleTimeout::SESSION_KEY => time() - 86400]);

    $this->get(SecuritySettingsPage::getUrl(panel: 'admin'))->assertOk();

    expect(Auth::guard('admin')->check())->toBeTrue();
});
