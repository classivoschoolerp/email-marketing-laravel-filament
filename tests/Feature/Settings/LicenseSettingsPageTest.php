<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\LicenseSettingsPage;
use App\Models\Customer;
use App\Models\User;
use App\Settings\LicenseSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// F-3.25.12-001
it('renders the license settings page for an admin', function (): void {
    $this->get(LicenseSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.license.title'))
        // F-3.25.12-002 — status display
        ->assertSee(__('settings.license.statuses.unknown'));
});

it('denies the customer guard access to the license settings page', function (): void {
    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get(LicenseSettingsPage::getUrl(panel: 'admin'))->assertRedirect();
});

// F-3.25.12-001 — key entry persists (encrypted at rest)
it('persists the license key encrypted at rest', function (): void {
    Livewire::test(LicenseSettingsPage::class)
        ->fillForm(['license_key' => 'WP05-LICENSE-KEY'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(LicenseSettings::class)->refresh()->license_key)->toBe('WP05-LICENSE-KEY');

    $payload = (string) DB::table('settings')
        ->where('group', 'license')
        ->where('name', 'license_key')
        ->value('payload');

    expect($payload)->not->toContain('WP05-LICENSE-KEY');
});

// F-3.25.12-001/002/003 — validate button refreshes the stored status
it('validates the entered key from the page action and stores the result', function (): void {
    config(['license.endpoint' => 'https://license.example.test/api/validate']);

    Http::fake([
        'https://license.example.test/api/validate' => Http::response([
            'valid' => true,
            'type' => 'commercial',
            'expires_at' => '2027-06-30',
            'allowed_domains' => ['example.com'],
            'renewal_url' => 'https://license.example.test/renew/XYZ',
        ]),
    ]);

    Livewire::test(LicenseSettingsPage::class)
        ->fillForm(['license_key' => 'NEW-KEY'])
        ->callAction('validateLicense')
        ->assertHasNoActionErrors()
        ->assertNotified(__('settings.license.messages.valid'));

    $settings = app(LicenseSettings::class)->refresh();

    expect($settings->license_key)->toBe('NEW-KEY')
        ->and($settings->status)->toBe(LicenseSettings::STATUS_VALID)
        ->and($settings->type)->toBe('commercial')
        ->and($settings->expires_at)->toBe('2027-06-30')
        ->and($settings->allowed_domains)->toBe(['example.com'])
        ->and($settings->renewal_url)->toBe('https://license.example.test/renew/XYZ');
});

it('notifies about an invalid key from the page action', function (): void {
    config(['license.endpoint' => 'https://license.example.test/api/validate']);

    Http::fake([
        'https://license.example.test/api/validate' => Http::response(['valid' => false]),
    ]);

    Livewire::test(LicenseSettingsPage::class)
        ->fillForm(['license_key' => 'BAD-KEY'])
        ->callAction('validateLicense')
        ->assertNotified(__('settings.license.messages.check_failed'));

    expect(app(LicenseSettings::class)->refresh()->status)->toBe(LicenseSettings::STATUS_INVALID);
});

// D-09 — self-hosted mode via the page action
it('reports self-hosted valid mode when no endpoint is configured', function (): void {
    config(['license.endpoint' => null]);
    Http::fake();

    Livewire::test(LicenseSettingsPage::class)
        ->callAction('validateLicense')
        ->assertNotified(__('settings.license.messages.valid'));

    $settings = app(LicenseSettings::class)->refresh();

    expect($settings->status)->toBe(LicenseSettings::STATUS_VALID)
        ->and($settings->type)->toBe('self-hosted');

    Http::assertNothingSent();
});
