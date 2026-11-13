<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\SecuritySettingsPage;
use App\Http\Middleware\AdminIpAllowlist;
use App\Models\EmailList;
use App\Models\User;
use App\Settings\SecuritySettings;
use Filament\Facades\Filament;

/*
 * WP-22 — F-3.25.15-005: allowed admin IPs. The middleware sits on the admin
 * panel's stack (before authentication) via ComplianceServiceProvider.
 */

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('registers the allowlist middleware on the admin panel only', function (): void {
    expect(Filament::getPanel('admin')->getMiddleware())->toContain(AdminIpAllowlist::class)
        ->and(Filament::getPanel('customer')->getMiddleware())->not->toContain(AdminIpAllowlist::class);
});

it('allows every IP while the allowlist is empty', function (): void {
    $this->get(SecuritySettingsPage::getUrl(panel: 'admin'))->assertOk();
});

// F-3.25.15-005
it('blocks an admin request from an IP outside the allowlist', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->admin_allowed_ips = ['203.0.113.4'];
    $settings->save();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->get(SecuritySettingsPage::getUrl(panel: 'admin'))
        ->assertForbidden();
});

it('allows an exact match and a CIDR range', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->admin_allowed_ips = ['203.0.113.4', '198.51.100.0/24'];
    $settings->save();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])
        ->get(SecuritySettingsPage::getUrl(panel: 'admin'))
        ->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
        ->get(SecuritySettingsPage::getUrl(panel: 'admin'))
        ->assertOk();
});

// The allowlist must never touch the public frontend or the customer panel.
it('never restricts non-admin routes', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->admin_allowed_ips = ['203.0.113.4'];
    $settings->save();

    $list = EmailList::factory()->create(['visible' => true, 'status' => EmailList::STATUS_ACTIVE]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->get(route('lists.public.subscribe', ['list_uid' => $list->uid]))
        ->assertOk();
});
