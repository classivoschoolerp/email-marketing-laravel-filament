<?php

declare(strict_types=1);

use App\Filament\Customer\Pages\Security;
use App\Models\Customer;
use App\Models\User;
use App\Settings\TwoFactorSettings;

function twofaSettings(array $overrides): void
{
    $settings = app(TwoFactorSettings::class);

    foreach ($overrides as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();
}

// F-3.25.6-002 — force for customers
it('redirects a customer without 2FA to the security page when forced', function (): void {
    twofaSettings(['enabled' => true, 'force_customers' => true]);

    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get('/customer')
        ->assertRedirect(Security::getUrl(panel: 'customer'));

    $notifications = collect(session('filament.notifications', []));

    expect($notifications->pluck('title'))
        ->toContain(__('settings.twofa.notices.customer_required_title'));
});

it('keeps the security page and logout reachable for a forced customer', function (): void {
    twofaSettings(['enabled' => true, 'force_customers' => true]);

    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get(Security::getUrl(panel: 'customer'))->assertOk();
});

it('lets a customer with 2FA enabled through when forced', function (): void {
    twofaSettings(['enabled' => true, 'force_customers' => true]);

    $customer = Customer::factory()->create();
    $customer->forceFill(['twofa_enabled' => true, 'twofa_secret' => 'SECRET'])->save();

    $this->actingAs($customer, 'customer');

    $this->get('/customer')->assertOk();
});

it('does not redirect customers when 2FA is not forced', function (): void {
    twofaSettings(['enabled' => true, 'force_customers' => false]);

    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get('/customer')->assertOk();
});

it('suspends enforcement when 2FA is disabled system-wide', function (): void {
    twofaSettings(['enabled' => false, 'force_customers' => true, 'force_admins' => true]);

    $this->actingAs(Customer::factory()->create(), 'customer');
    $this->get('/customer')->assertOk();

    expect(session('filament.notifications', []))->toBe([]);
});

// F-3.25.6-002 — force for admins (WP-03 closed D-35: admins enrol on the
// admin Security page, so enforcement now mirrors the customer redirect)
it('redirects an admin without 2FA to the admin security page when forced', function (): void {
    twofaSettings(['enabled' => true, 'force_admins' => true]);

    $this->actingAs(User::factory()->create(), 'admin');

    $this->get('/admin')
        ->assertRedirect(\App\Filament\Admin\Pages\Security::getUrl(panel: 'admin'));

    $notifications = collect(session('filament.notifications', []));

    expect($notifications->pluck('title'))
        ->toContain(__('settings.twofa.notices.admin_required_title'));
});

it('keeps the admin security page and logout reachable when 2FA is forced', function (): void {
    twofaSettings(['enabled' => true, 'force_admins' => true]);

    $this->actingAs(User::factory()->create(), 'admin');

    $this->get(\App\Filament\Admin\Pages\Security::getUrl(panel: 'admin'))->assertOk();
});

it('lets an admin with 2FA enabled through when forced', function (): void {
    twofaSettings(['enabled' => true, 'force_admins' => true]);

    $admin = User::factory()->create();
    $admin->forceFill(['twofa_enabled' => true, 'twofa_secret' => 'SECRET'])->save();

    $this->actingAs($admin, 'admin');

    $this->get('/admin')->assertOk();
});

it('does not redirect admins when 2FA is not forced for them', function (): void {
    twofaSettings(['enabled' => true, 'force_admins' => false]);

    $this->actingAs(User::factory()->create(), 'admin');

    $this->get('/admin')->assertOk();
});
