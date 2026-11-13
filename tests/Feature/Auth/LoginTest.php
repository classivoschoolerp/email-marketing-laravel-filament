<?php

declare(strict_types=1);

use App\Enums\CustomerStatus;
use App\Enums\LoginResult;
use App\Filament\Customer\Auth\Login;
use App\Models\Customer;
use App\Models\LoginLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

function onCustomerPanel(): void
{
    Filament::setCurrentPanel(Filament::getPanel('customer'));
}

// F-1.3-001 — email + password login through the panel
it('authenticates a customer with email and password', function (): void {
    onCustomerPanel();

    $customer = Customer::factory()->create(['password' => 'Secret123']);

    Livewire::test(Login::class)
        ->fillForm(['email' => $customer->email, 'password' => 'Secret123'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(Auth::guard('customer')->id())->toBe($customer->id);
});

it('rejects invalid credentials', function (): void {
    onCustomerPanel();

    $customer = Customer::factory()->create(['password' => 'Secret123']);

    Livewire::test(Login::class)
        ->fillForm(['email' => $customer->email, 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasErrors(['data.email']);

    expect(Auth::guard('customer')->check())->toBeFalse();
});

// F-1.3-002 — remember me
it('supports remember me', function (): void {
    onCustomerPanel();

    $customer = Customer::factory()->create(['password' => 'Secret123', 'remember_token' => null]);

    Livewire::test(Login::class)
        ->fillForm(['email' => $customer->email, 'password' => 'Secret123', 'remember' => true])
        ->call('authenticate');

    expect(Auth::guard('customer')->check())->toBeTrue()
        ->and($customer->fresh()->remember_token)->not->toBeNull();
});

// F-1.3-006 — blocked/inactive account messaging
it('shows friendly messaging for blocked and inactive accounts', function (): void {
    onCustomerPanel();

    $blocked = Customer::factory()->blocked()->create(['password' => 'Secret123']);

    Livewire::test(Login::class)
        ->fillForm(['email' => $blocked->email, 'password' => 'Secret123'])
        ->call('authenticate')
        ->assertHasErrors(['data.email']);

    expect(Auth::guard('customer')->check())->toBeFalse();

    // The messaging map covers every non-active status distinctly (F-1.3-006).
    expect(Login::statusMessage(CustomerStatus::Blocked))->toBe(__('auth-module.login.blocked'))
        ->and(Login::statusMessage(CustomerStatus::Inactive))->toBe(__('auth-module.login.inactive'))
        ->and(Login::statusMessage(CustomerStatus::PendingConfirm))->toBe(__('auth-module.login.pending_confirm'))
        ->and(Login::statusMessage(CustomerStatus::PendingApprove))->toBe(__('auth-module.login.pending_approve'));

    // A blocked attempt with valid credentials is logged as "blocked" (F-1.3-005).
    expect(
        LoginLog::query()
            ->where('guard', 'customer')
            ->where('email', $blocked->email)
            ->where('result', LoginResult::Blocked->value)
            ->exists()
    )->toBeTrue();
});

// F-1.3-004 — failed-attempt throttling + IP lockout
it('locks out after repeated failed attempts', function (): void {
    onCustomerPanel();
    // WP-22 (§3.25.15 F-3.25.15-001): the attempt limit moved into the
    // `security` settings group; config/emp.php is now only the pre-migration
    // fallback. Both are set so the test states the limit unambiguously.
    config()->set('emp.login.max_attempts', 3);
    $security = app(\App\Settings\SecuritySettings::class);
    $security->login_max_attempts = 3;
    $security->save();

    $customer = Customer::factory()->create(['password' => 'Secret123']);

    foreach (range(1, 3) as $attempt) {
        Livewire::test(Login::class)
            ->fillForm(['email' => $customer->email, 'password' => 'nope'])
            ->call('authenticate')
            ->assertHasErrors(['data.email']);
    }

    // Even correct credentials are refused while locked out.
    Livewire::test(Login::class)
        ->fillForm(['email' => $customer->email, 'password' => 'Secret123'])
        ->call('authenticate')
        ->assertHasErrors(['data.email']);

    expect(Auth::guard('customer')->check())->toBeFalse()
        ->and(
            LoginLog::query()
                ->where('email', $customer->email)
                ->where('result', LoginResult::Lockout->value)
                ->exists()
        )->toBeTrue();
});

it('locks out an IP that rotates target accounts', function (): void {
    onCustomerPanel();
    // WP-22 (§3.25.15 F-3.25.15-001) — see the note above.
    config()->set('emp.login.ip_max_attempts', 2);
    $security = app(\App\Settings\SecuritySettings::class);
    $security->login_ip_max_attempts = 2;
    $security->save();

    [$a, $b, $c] = Customer::factory()->count(3)->create(['password' => 'Secret123']);

    foreach ([$a, $b] as $target) {
        Livewire::test(Login::class)
            ->fillForm(['email' => $target->email, 'password' => 'nope'])
            ->call('authenticate');
    }

    // Third account, same IP — refused despite valid credentials.
    Livewire::test(Login::class)
        ->fillForm(['email' => $c->email, 'password' => 'Secret123'])
        ->call('authenticate')
        ->assertHasErrors(['data.email']);

    expect(Auth::guard('customer')->check())->toBeFalse();
});

// F-1.3-005 — login log records IP/UA/result for both guards
it('records login logs for both guards', function (): void {
    onCustomerPanel();

    $customer = Customer::factory()->create(['password' => 'Secret123']);

    // Failure first…
    Livewire::test(Login::class)
        ->fillForm(['email' => $customer->email, 'password' => 'nope'])
        ->call('authenticate');

    // …then success.
    Livewire::test(Login::class)
        ->fillForm(['email' => $customer->email, 'password' => 'Secret123'])
        ->call('authenticate');

    $failure = LoginLog::query()->where('email', $customer->email)->where('result', LoginResult::Failed->value)->first();
    $success = LoginLog::query()->where('email', $customer->email)->where('result', LoginResult::Success->value)->first();

    expect($failure)->not->toBeNull()
        ->and($success)->not->toBeNull()
        ->and($success->guard)->toBe('customer')
        ->and($success->ip)->not->toBeNull()
        ->and((int) $success->model_id)->toBe($customer->id)
        ->and($customer->fresh()->last_login_at)->not->toBeNull();

    // Admin guard: the stock Filament login fires the same events.
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $admin = User::factory()->create();

    Livewire::test(\Filament\Pages\Auth\Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'password'])
        ->call('authenticate');

    expect(
        LoginLog::query()
            ->where('guard', 'admin')
            ->where('email', $admin->email)
            ->where('result', LoginResult::Success->value)
            ->exists()
    )->toBeTrue();
});
