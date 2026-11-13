<?php

declare(strict_types=1);

namespace App\Filament\Customer\Auth;

use App\Enums\CustomerStatus;
use App\Enums\LoginResult;
use App\Models\Customer;
use App\Models\LoginLog;
use App\Services\Auth\LoginThrottle;
use App\Services\Security\AccountIpAllowlist;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Customer panel login (spec §1.3): Filament's login page plus
 * - TOTP 2FA challenge hand-off after the password step (F-1.3-003) — the
 *   session is NOT authenticated until the challenge passes,
 * - config-driven failed-attempt throttling + IP lockout (F-1.3-004),
 * - friendly blocked/inactive/pending messaging (F-1.3-006),
 * - login log recording via auth events (F-1.3-005).
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();
        $email = (string) $data['email'];
        $ip = (string) request()->ip();
        $throttle = app(LoginThrottle::class);

        if ($throttle->tooManyAttempts($email, $ip)) {
            LoginLog::record('customer', null, $email, LoginResult::Lockout);

            throw ValidationException::withMessages([
                'data.email' => __('auth-module.login.throttled', [
                    'seconds' => $throttle->availableIn($email, $ip),
                ]),
            ]);
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()->where('email', mb_strtolower($email))->first();

        if ($customer === null || ! Hash::check((string) $data['password'], $customer->password)) {
            $throttle->hit($email, $ip);
            event(new Failed('customer', $customer, ['email' => $email]));

            $this->throwFailureValidationException();
        }

        if (! $customer->status->canLogin()) {
            event(new Failed('customer', $customer, ['email' => $email]));

            throw ValidationException::withMessages([
                'data.email' => self::statusMessage($customer->status),
            ]);
        }

        // §2.15.2 F-2.15.2-004 — the account's own IP allowlist, checked
        // AFTER the password so a wrong password never reveals whether an
        // allowlist exists, and BEFORE any session (including the parked 2FA
        // one) so a disallowed address cannot reach the challenge either.
        // EnforceAccountIpAllowlist covers the other entry points.
        if (! app(AccountIpAllowlist::class)->allows($customer, $ip)) {
            LoginLog::record('customer', $customer, $email, LoginResult::Blocked);
            $throttle->hit($email, $ip);

            throw ValidationException::withMessages([
                'data.email' => __('auth-module.ip_allowlist.blocked'),
            ]);
        }

        $remember = (bool) ($data['remember'] ?? false);

        if ($customer->twofa_enabled) {
            // Password accepted — park the login and require the TOTP challenge
            // before any authenticated session exists (F-1.3-003).
            session()->put([
                'emp.2fa.id' => $customer->getKey(),
                'emp.2fa.remember' => $remember,
            ]);

            $throttle->clear($email, $ip);

            $this->redirect(route('customer.two-factor'));

            return null;
        }

        Filament::auth()->login($customer, $remember);

        if (! $customer->canAccessPanel(Filament::getCurrentPanel())) {
            Filament::auth()->logout();

            $this->throwFailureValidationException();
        }

        session()->regenerate();
        $throttle->clear($email, $ip);

        return app(LoginResponse::class);
    }

    public static function statusMessage(CustomerStatus $status): string
    {
        return match ($status) {
            CustomerStatus::Blocked => __('auth-module.login.blocked'),
            CustomerStatus::Inactive => __('auth-module.login.inactive'),
            CustomerStatus::PendingConfirm => __('auth-module.login.pending_confirm'),
            CustomerStatus::PendingApprove => __('auth-module.login.pending_approve'),
            CustomerStatus::Active => __('filament-panels::pages/auth/login.messages.failed'),
        };
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->hint(new HtmlString(Blade::render(
                '<x-filament::link :href="route(\'password.request\')" tabindex="3">'
                .e(__('auth-module.login.forgot_password'))
                .'</x-filament::link>'
            )));
    }
}
