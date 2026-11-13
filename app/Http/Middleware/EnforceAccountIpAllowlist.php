<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Providers\ComplianceServiceProvider;
use App\Services\Security\AccountIpAllowlist;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Per-account IP allowlist enforcement (spec §2.15.2 F-2.15.2-004, WP-46).
 *
 * Registered on the CUSTOMER panel's auth middleware stack by
 * {@see ComplianceServiceProvider}, alongside {@see EnforceIdleTimeout} —
 * the established "mutate from a provider, never edit the WP-01 panel
 * providers" pattern.
 *
 * Why here as well as on the login form: the login form is only ONE way into
 * a customer session. Social login (§6.7 F-6.7-003), the one-time login token
 * (F-6.7-001), the 2FA challenge hand-off and a remembered cookie all produce
 * an authenticated session without passing through
 * {@see \App\Filament\Customer\Auth\Login::authenticate()}. Enforcing at the
 * panel covers every one of them, and also cuts off a session whose owner
 * moved to a disallowed address mid-way.
 *
 * The check is deliberately NOT applied to the admin panel: an operator
 * impersonating a customer (F-0.2-006) authenticates on the admin guard, and
 * a tenant's own allowlist must not be able to lock the operator out of their
 * own installation.
 *
 * Sub-users (§0.2 F-0.2-004, D-109) authenticate as the PARENT Customer, so
 * the owner's allowlist governs the whole team by construction. That is the
 * intended reading of "IP allowlist for account access".
 */
class EnforceAccountIpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $customer = Auth::guard('customer')->user();

            if (! $customer instanceof Customer) {
                return $next($request);
            }

            $allowlist = app(AccountIpAllowlist::class);

            if ($allowlist->allows($customer, $request->ip())) {
                return $next($request);
            }
        } catch (Throwable) {
            // Pre-migration / installer: never lock anybody out over a column
            // that does not exist yet (EnforceIdleTimeout makes the same call).
            return $next($request);
        }

        Auth::guard('customer')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = __('auth-module.ip_allowlist.blocked');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        return redirect()->to('/customer/login')->with('status', $message);
    }
}
