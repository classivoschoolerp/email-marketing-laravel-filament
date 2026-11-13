<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\LoginResult;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoginLog;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * TOTP challenge shown after a valid password when 2FA is enabled (spec §1.3
 * F-1.3-003). Accepts an authenticator code or a single-use recovery code.
 */
class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user('customer') !== null) {
            return redirect('/customer');
        }

        if (! $request->session()->has('emp.2fa.id')) {
            return redirect('/customer/login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(
        Request $request,
        TwoFactorService $twoFactor,
        LoginThrottle $throttle,
    ): RedirectResponse {
        $id = $request->session()->get('emp.2fa.id');

        if ($id === null) {
            return redirect('/customer/login')->withErrors(['code' => __('auth-module.twofa.expired')]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        /** @var Customer|null $customer */
        $customer = Customer::query()->find($id);

        if ($customer === null || ! $customer->status->canLogin()) {
            $request->session()->forget(['emp.2fa.id', 'emp.2fa.remember']);

            return redirect('/customer/login');
        }

        $ip = (string) $request->ip();

        if ($throttle->tooManyAttempts($customer->email, $ip)) {
            LoginLog::record('customer', $customer, $customer->email, LoginResult::Lockout, $request);

            return back()->withErrors(['code' => __('auth-module.login.throttled', [
                'seconds' => $throttle->availableIn($customer->email, $ip),
            ])]);
        }

        if (! $twoFactor->verify($customer, trim((string) $validated['code']))) {
            $throttle->hit($customer->email, $ip);
            LoginLog::record('customer', $customer, $customer->email, LoginResult::TwoFactorFailed, $request);

            return back()->withErrors(['code' => __('auth-module.twofa.invalid_code')]);
        }

        $remember = (bool) $request->session()->pull('emp.2fa.remember', false);
        $request->session()->forget('emp.2fa.id');

        Auth::guard('customer')->login($customer, $remember); // fires Login → success log
        $request->session()->regenerate();
        $throttle->clear($customer->email, $ip);

        return redirect()->intended('/customer');
    }
}
