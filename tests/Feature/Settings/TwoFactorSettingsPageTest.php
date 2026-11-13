<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\TwoFactorSettingsPage;
use App\Models\Customer;
use App\Models\CustomerRecoveryCode;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Settings\TwoFactorSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// F-3.25.6-001
it('renders the 2FA settings page for an admin', function (): void {
    $this->get(TwoFactorSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.twofa.title'));
});

it('denies the customer guard access to the 2FA settings page', function (): void {
    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get(TwoFactorSettingsPage::getUrl(panel: 'admin'))->assertRedirect();
});

it('shows the seeded 2FA defaults', function (): void {
    Livewire::test(TwoFactorSettingsPage::class)
        ->assertFormSet([
            'enabled' => true,
            'force_admins' => false,
            'force_customers' => false,
            'issuer' => null,
            'code_validity_window' => 1,
            'recovery_code_count' => 10,
        ]);
});

// F-3.25.6-001 … F-3.25.6-003
it('persists 2FA settings', function (): void {
    Livewire::test(TwoFactorSettingsPage::class)
        ->fillForm([
            'enabled' => true,
            'force_admins' => true,
            'force_customers' => true,
            'issuer' => 'Acme Mailer',
            'code_validity_window' => 2,
            'recovery_code_count' => 12,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(TwoFactorSettings::class)->refresh();

    expect($settings->force_admins)->toBeTrue()
        ->and($settings->force_customers)->toBeTrue()
        ->and($settings->issuer)->toBe('Acme Mailer')
        ->and($settings->code_validity_window)->toBe(2)
        ->and($settings->recovery_code_count)->toBe(12);
});

// F-3.25.6-003
it('rejects out-of-range 2FA numbers', function (): void {
    Livewire::test(TwoFactorSettingsPage::class)
        ->fillForm([
            'code_validity_window' => -1,
            'recovery_code_count' => 0,
        ])
        ->call('save')
        ->assertHasFormErrors(['code_validity_window', 'recovery_code_count']);
});

// F-3.25.6-003 — the recovery-code count setting drives the TOTP service
it('generates the configured number of recovery codes', function (): void {
    $settings = app(TwoFactorSettings::class);
    $settings->recovery_code_count = 4;
    $settings->save();

    $customer = Customer::factory()->create();
    $codes = app(TwoFactorService::class)->generateRecoveryCodes($customer);

    expect($codes)->toHaveCount(4)
        ->and(CustomerRecoveryCode::query()->where('customer_id', $customer->id)->count())->toBe(4);
});

// F-3.25.6-003 — issuer falls back to the app name until configured
it('exposes the issuer through the reader with app-name fallback', function (): void {
    expect(\App\Services\Settings\SettingsReader::twoFactor()->issuer())
        ->toBe((string) config('app.name'));

    $settings = app(TwoFactorSettings::class);
    $settings->issuer = 'Custom Issuer Co';
    $settings->save();

    expect(\App\Services\Settings\SettingsReader::twoFactor()->issuer())->toBe('Custom Issuer Co');

    // The provisioning QR still renders with the custom issuer in place.
    $customer = Customer::factory()->create();
    $service = app(TwoFactorService::class);

    expect($service->qrCodeSvg($customer, $service->generateSecret()))->toContain('<svg');
});
