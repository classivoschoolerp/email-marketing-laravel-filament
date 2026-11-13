<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Notifications\Auth\ResetPasswordLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * Public "forgot password" flow for customers (spec §1.4 F-1.4-001):
 * tokenised link via the `customers` password broker, with expiry + throttle
 * from config/auth.php.
 */
class PasswordResetLinkController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user('customer') !== null) {
            return redirect('/customer');
        }

        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
        ]);

        Password::broker('customers')->sendResetLink(
            ['email' => mb_strtolower($validated['email'])],
            fn (Customer $customer, string $token) => $customer->notify(new ResetPasswordLink($token)),
        );

        // Same response whether or not the account exists — no enumeration.
        return back()->with('status', __('auth-module.reset.link_sent'));
    }
}
