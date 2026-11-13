<?php

declare(strict_types=1);

use App\Enums\LoginResult;
use App\Filament\Customer\Auth\Login;
use App\Models\Customer;
use App\Models\LoginLog;
use App\Services\Auth\TwoFactorService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/** @return array{0: Customer, 1: string} customer + plaintext TOTP secret */
function customerWithTwoFactor(): array
{
    $secret = app(TwoFactorService::class)->generateSecret();

    $customer = Customer::factory()->create([
        'password' => 'Secret123',
        'twofa_secret' => $secret, // encrypted cast
        'twofa_enabled' => true,
    ]);

    return [$customer, $secret];
}

// F-1.3-003 — password success hands off to the TOTP challenge, unauthenticated
it('redirects to the challenge after a valid password when 2FA is enabled', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    [$customer] = customerWithTwoFactor();

    Livewire::test(Login::class)
        ->fillForm(['email' => $customer->email, 'password' => 'Secret123'])
        ->call('authenticate')
        ->assertRedirect(route('customer.two-factor'));

    expect(Auth::guard('customer')->check())->toBeFalse()
        ->and(session('emp.2fa.id'))->toBe($customer->id);
});

it('completes login with a valid TOTP code', function (): void {
    [$customer, $secret] = customerWithTwoFactor();

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->withSession(['emp.2fa.id' => $customer->id, 'emp.2fa.remember' => false])
        ->post(route('customer.two-factor.verify'), ['code' => $code])
        ->assertRedirect('/customer');

    expect(Auth::guard('customer')->id())->toBe($customer->id)
        ->and(session()->has('emp.2fa.id'))->toBeFalse()
        ->and(
            LoginLog::query()
                ->where('email', $customer->email)
                ->where('result', LoginResult::Success->value)
                ->exists()
        )->toBeTrue();
});

it('rejects an invalid code and logs the failure', function (): void {
    [$customer] = customerWithTwoFactor();

    $this->withSession(['emp.2fa.id' => $customer->id])
        ->post(route('customer.two-factor.verify'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect(Auth::guard('customer')->check())->toBeFalse()
        ->and(
            LoginLog::query()
                ->where('email', $customer->email)
                ->where('result', LoginResult::TwoFactorFailed->value)
                ->exists()
        )->toBeTrue();
});

// F-2.15.2-001 (recovery codes usable at login) — single use
it('accepts a recovery code once and consumes it', function (): void {
    [$customer] = customerWithTwoFactor();

    $codes = app(TwoFactorService::class)->generateRecoveryCodes($customer);

    $this->withSession(['emp.2fa.id' => $customer->id])
        ->post(route('customer.two-factor.verify'), ['code' => $codes[0]])
        ->assertRedirect('/customer');

    expect(Auth::guard('customer')->id())->toBe($customer->id);

    Auth::guard('customer')->logout();

    // Replaying the consumed code fails.
    $this->withSession(['emp.2fa.id' => $customer->id])
        ->post(route('customer.two-factor.verify'), ['code' => $codes[0]])
        ->assertSessionHasErrors('code');

    expect(Auth::guard('customer')->check())->toBeFalse();
});

it('guards the challenge page without a pending login', function (): void {
    $this->get(route('customer.two-factor'))->assertRedirect('/customer/login');

    $this->post(route('customer.two-factor.verify'), ['code' => '123456'])
        ->assertRedirect('/customer/login');
});

it('throttles repeated wrong 2FA codes', function (): void {
    // WP-22 (§3.25.15 F-3.25.15-001): the attempt limit now lives in the
    // `security` settings group; config/emp.php is the pre-migration fallback.
    config()->set('emp.login.max_attempts', 2);
    $security = app(\App\Settings\SecuritySettings::class);
    $security->login_max_attempts = 2;
    $security->save();

    [$customer, $secret] = customerWithTwoFactor();

    foreach (range(1, 2) as $i) {
        $this->withSession(['emp.2fa.id' => $customer->id])
            ->post(route('customer.two-factor.verify'), ['code' => '000000']);
    }

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->withSession(['emp.2fa.id' => $customer->id])
        ->post(route('customer.two-factor.verify'), ['code' => $code])
        ->assertSessionHasErrors('code');

    expect(Auth::guard('customer')->check())->toBeFalse()
        ->and(
            LoginLog::query()
                ->where('email', $customer->email)
                ->where('result', LoginResult::Lockout->value)
                ->exists()
        )->toBeTrue();
});
