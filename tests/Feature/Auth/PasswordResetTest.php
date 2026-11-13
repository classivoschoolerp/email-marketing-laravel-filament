<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\ResetPasswordLink;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

// F-1.4-001 — request reset by email, tokenised link
it('emails a tokenised reset link', function (): void {
    Notification::fake();

    $customer = Customer::factory()->create();

    $this->post(route('password.email'), ['email' => $customer->email])
        ->assertRedirect()
        ->assertSessionHas('status', __('auth-module.reset.link_sent'));

    Notification::assertSentTo($customer, ResetPasswordLink::class);
});

it('responds identically for unknown addresses', function (): void {
    Notification::fake();

    $this->post(route('password.email'), ['email' => 'ghost@example.com'])
        ->assertRedirect()
        ->assertSessionHas('status', __('auth-module.reset.link_sent'));

    Notification::assertNothingSent();
});

// F-1.4-001 + F-1.4-003 — reset works and notifies on change
it('resets the password with a valid token and sends the change notice', function (): void {
    Notification::fake();

    $customer = Customer::factory()->create();
    $token = Password::broker('customers')->createToken($customer);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $customer->email,
        'password' => 'NewSecret123',
        'password_confirmation' => 'NewSecret123',
    ])->assertRedirect('/customer/login');

    expect(Hash::check('NewSecret123', $customer->fresh()->password))->toBeTrue();

    Notification::assertSentTo($customer, PasswordChangedNotification::class);
});

// F-1.4-001 — expiry honoured
it('rejects expired reset tokens', function (): void {
    $customer = Customer::factory()->create();
    $token = Password::broker('customers')->createToken($customer);

    $this->travel((int) config('auth.passwords.customers.expire') + 1)->minutes();

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $customer->email,
        'password' => 'NewSecret123',
        'password_confirmation' => 'NewSecret123',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('NewSecret123', $customer->fresh()->password))->toBeFalse();
});

// F-1.4-002 — reset form + strength meter
it('renders the reset form with a password strength meter', function (): void {
    $customer = Customer::factory()->create();
    $token = Password::broker('customers')->createToken($customer);

    $this->get(route('password.reset', ['token' => $token, 'email' => $customer->email]))
        ->assertOk()
        ->assertSee('id="strength-meter"', false)
        ->assertSee(__('auth-module.reset.strength.label'));
});

// F-1.4-002 — strength rules: min 8, mixed case, numbers
it('enforces password strength rules on reset', function (): void {
    $customer = Customer::factory()->create();
    $token = Password::broker('customers')->createToken($customer);

    foreach (['short1A', 'alllowercase1', 'NoNumbersHere'] as $weak) {
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $customer->email,
            'password' => $weak,
            'password_confirmation' => $weak,
        ])->assertSessionHasErrors('password');
    }
});

it('renders the forgot-password form', function (): void {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee(__('auth-module.reset.request_title'));
});
