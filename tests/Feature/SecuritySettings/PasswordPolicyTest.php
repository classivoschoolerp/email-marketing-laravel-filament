<?php

declare(strict_types=1);

use App\Filament\Customer\Pages\Profile;
use App\Models\Customer;
use App\Models\PasswordHistory;
use App\Models\User;
use App\Rules\PasswordPolicy;
use App\Services\Security\PasswordExpiry;
use App\Settings\SecuritySettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

/*
 * WP-22 — F-3.25.15-003: password policy (length, complexity, expiry,
 * reuse) enforced through App\Rules\PasswordPolicy + the password history
 * listener wired from ComplianceServiceProvider.
 */

function policyPasses(string $password, ?object $user = null): bool
{
    return validator(
        ['password' => $password],
        ['password' => PasswordPolicy::rules($user)],
    )->passes();
}

// F-3.25.15-003 (length + complexity)
it('derives the strength rule from the settings', function (): void {
    expect(policyPasses('Secret123'))->toBeTrue()
        ->and(policyPasses('secret123'))->toBeFalse()   // no upper case
        ->and(policyPasses('SecretPass'))->toBeFalse()  // no digit
        ->and(policyPasses('Sec12'))->toBeFalse();      // too short

    $settings = app(SecuritySettings::class);
    $settings->password_min_length = 14;
    $settings->password_require_symbols = true;
    $settings->save();

    expect(policyPasses('Secret123'))->toBeFalse()
        ->and(policyPasses('SecretPassword1'))->toBeFalse() // no symbol
        ->and(policyPasses('SecretPassword1!'))->toBeTrue();
});

// The Password::defaults() override makes every existing caller settings-aware.
it('drives Password::defaults from the settings', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->password_min_length = 16;
    $settings->save();

    expect(validator(['p' => 'Secret1234'], ['p' => Password::defaults()])->passes())->toBeFalse()
        ->and(validator(['p' => 'Secret1234567890'], ['p' => Password::defaults()])->passes())->toBeTrue();
});

// F-3.25.15-003 (reuse prevention → password_histories)
it('records a password history row on every password change', function (): void {
    $customer = Customer::factory()->create(['password' => 'Secret123']);

    expect(PasswordHistory::query()
        ->where('authenticatable_type', $customer->getMorphClass())
        ->where('authenticatable_id', $customer->id)
        ->count())->toBe(1);

    $customer->forceFill(['password' => 'Another456'])->save();

    $hashes = PasswordHistory::query()
        ->where('authenticatable_id', $customer->id)
        ->pluck('password_hash');

    expect($hashes)->toHaveCount(2)
        ->and($hashes->contains(fn (string $h): bool => Hash::check('Secret123', $h)))->toBeTrue()
        ->and($hashes->contains(fn (string $h): bool => Hash::check('Another456', $h)))->toBeTrue();

    // password_changed_at is stamped for the expiry check.
    expect($customer->fresh()->password_changed_at)->not->toBeNull();
});

it('blocks reusing the current or a recent password when prevention is on', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->password_reuse_prevention = 3;
    $settings->save();

    $customer = Customer::factory()->create(['password' => 'Secret123']);
    $customer->forceFill(['password' => 'Another456'])->save();
    $customer = $customer->fresh();

    expect(policyPasses('Another456', $customer))->toBeFalse()  // current
        ->and(policyPasses('Secret123', $customer))->toBeFalse() // previous
        ->and(policyPasses('Totally789New', $customer))->toBeTrue();
});

// D-23: 0 = reuse prevention disabled.
it('allows reuse when prevention is zero', function (): void {
    $customer = Customer::factory()->create(['password' => 'Secret123']);

    expect(policyPasses('Secret123', $customer->fresh()))->toBeTrue();
});

it('trims the password history to the configured depth', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->password_reuse_prevention = 2;
    $settings->save();

    $customer = Customer::factory()->create(['password' => 'Secret123']);

    foreach (['Pass0001a', 'Pass0002b', 'Pass0003c', 'Pass0004d'] as $password) {
        $customer->forceFill(['password' => $password])->save();
    }

    expect(PasswordHistory::query()->where('authenticatable_id', $customer->id)->count())
        ->toBeLessThanOrEqual(3); // depth + 1 head-room
});

// F-3.25.15-003 wired into the customer Profile password form.
it('enforces the policy on the customer profile password change', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->password_reuse_prevention = 2;
    $settings->save();

    $customer = Customer::factory()->create(['password' => 'Secret123']);
    $this->actingAs($customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    Livewire::test(Profile::class)
        ->fillForm([
            'current_password' => 'Secret123',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
        ], 'passwordForm')
        ->call('savePassword')
        ->assertHasFormErrors(['password'], 'passwordForm');

    Livewire::test(Profile::class)
        ->fillForm([
            'current_password' => 'Secret123',
            'password' => 'BrandNew987',
            'password_confirmation' => 'BrandNew987',
        ], 'passwordForm')
        ->call('savePassword')
        ->assertHasNoFormErrors([], 'passwordForm');

    expect(Hash::check('BrandNew987', $customer->fresh()->password))->toBeTrue();
});

// F-3.25.15-003 wired into the customer password reset flow.
it('enforces the policy on a customer password reset', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->password_reuse_prevention = 2;
    $settings->save();

    $customer = Customer::factory()->create(['password' => 'Secret123']);
    $token = PasswordBroker::broker('customers')->createToken($customer);

    $this->post('/password/reset', [
        'token' => $token,
        'email' => $customer->email,
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
    ])->assertSessionHasErrors('password');

    $this->post('/password/reset', [
        'token' => $token,
        'email' => $customer->email,
        'password' => 'FreshPass321',
        'password_confirmation' => 'FreshPass321',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('FreshPass321', $customer->fresh()->password))->toBeTrue();
});

// F-3.25.15-003 (expiry enforcement)
it('redirects an admin with an expired password to the security page', function (): void {
    $admin = User::factory()->create();
    $this->actingAs($admin, 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // Expiry off → nothing happens.
    $this->get('/admin')->assertSuccessful();

    $settings = app(SecuritySettings::class);
    $settings->password_expiry_days = 30;
    $settings->save();

    $admin->forceFill(['password_changed_at' => now()->subDays(60)])->saveQuietly();

    $this->get('/admin')->assertRedirect('/admin/security');

    // The existing §3.2 "must change password" banner is switched on.
    expect($admin->fresh()->force_password_reset)->toBeTrue();

    // ...and the change page itself stays reachable.
    $this->get('/admin/security')->assertSuccessful();
});

it('leaves an admin with a fresh password alone', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->password_expiry_days = 30;
    $settings->save();

    $admin = User::factory()->create();
    $admin->forceFill(['password_changed_at' => now()->subDay()])->saveQuietly();

    $this->actingAs($admin, 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->get('/admin')->assertSuccessful();

    expect($admin->fresh()->force_password_reset)->toBeFalse();
});

// F-3.25.15-003 (expiry days)
it('evaluates password expiry from password_changed_at', function (): void {
    $expiry = app(PasswordExpiry::class);
    $user = User::factory()->create();

    expect($expiry->enabled())->toBeFalse()
        ->and($expiry->isExpired($user))->toBeFalse();

    $settings = app(SecuritySettings::class);
    $settings->password_expiry_days = 30;
    $settings->save();

    $user->forceFill(['password_changed_at' => now()->subDays(10)])->saveQuietly();
    expect($expiry->isExpired($user->fresh()))->toBeFalse();

    $user->forceFill(['password_changed_at' => now()->subDays(31)])->saveQuietly();
    expect($expiry->isExpired($user->fresh()))->toBeTrue()
        ->and($expiry->expiresAt($user->fresh())?->isPast())->toBeTrue();
});
