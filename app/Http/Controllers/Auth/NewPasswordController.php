<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Rules\PasswordPolicy;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Public reset form + submit for customers (spec §1.4 F-1.4-002): strength
 * rules Password::min(8)->mixedCase()->numbers(); the view renders a live
 * strength meter. Successful reset fires PasswordReset → notify-on-change
 * email (F-1.4-003).
 */
class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // WP-22 SURGICAL EDIT (§3.25.15 F-3.25.15-003): the shared password
        // policy replaces the hard-coded strength rule — same defaults, but
        // now settings-driven and reuse-aware for the account being reset.
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'password' => array_merge(
                ['required', 'string', 'confirmed'],
                PasswordPolicy::rules($this->customerFor($request)),
            ),
        ]);

        $status = Password::broker('customers')->reset(
            [
                'email' => mb_strtolower($validated['email']),
                'password' => $validated['password'],
                'token' => $validated['token'],
            ],
            function (Customer $customer) use ($validated): void {
                $customer->forceFill([
                    'password' => $validated['password'], // hashed cast
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($customer));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => __('auth-module.reset.failed')]);
        }

        Notification::make()
            ->title(__('auth-module.reset.done'))
            ->success()
            ->send();

        return redirect('/customer/login');
    }

    /**
     * The account the reset targets, for reuse prevention. Resolved before
     * validation runs; an unknown address simply yields null (the broker
     * rejects it afterwards with the generic failure message).
     */
    private function customerFor(Request $request): ?Customer
    {
        $email = $request->input('email');

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return Customer::query()->where('email', mb_strtolower(trim($email)))->first();
    }
}
