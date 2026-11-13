<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\Domain;
use App\Providers\ComplianceServiceProvider;
use App\Services\Branding\BrandResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Custom domain for the customer panel (spec §4.7 F-4.7-002, WP-46).
 *
 * The customer panel is path-based (`/customer`) and answers on every host;
 * `ResolveBrand` only repaints it. This middleware decides WHO a brand host
 * admits, which is the part an agency domain actually needs.
 *
 * Two independent rules, both off unless an operator turns them on, so an
 * installation with no `domains` rows — or with brand hosts that only serve
 * marketing content — is completely unaffected:
 *
 *  - `panel_enabled = false` → the panel 404s on this host. Not 403: on a
 *    host that never offered a panel, "this page does not exist" is the
 *    truth, and a 403 confirms to a scanner that accounts live behind it.
 *    This mirrors {@see EnforceDomainAuth}'s reasoning exactly.
 *  - `panel_restricted = true` → only accounts belonging to this brand may
 *    hold a session here. `domains.customer_id` narrows that to a single
 *    tenant (the §6.3 F-6.3-004 agency assignment).
 *
 * Registered on the customer panel by {@see ComplianceServiceProvider}: the
 * 404 half runs pre-auth (it is about the HOST, not the visitor), the
 * membership half only means anything once somebody is signed in, and is
 * therefore evaluated here too but skipped when no customer session exists.
 *
 * The ADMIN panel is deliberately untouched — an operator must always be
 * able to reach their own installation, including on a brand host, and
 * including while impersonating a customer (F-0.2-006).
 */
class EnforcePanelDomain
{
    public function __construct(private readonly BrandResolver $brands) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Resolved from the request host DIRECTLY rather than through
            // BrandContext: Filament decides the order of panel middleware,
            // and this class is in fact registered AHEAD of ResolveBrand, so
            // BrandContext::current() would lazily resolve to the app host
            // and silently disable the whole fence. A security check must
            // not depend on middleware ordering it does not control.
            $domain = $this->brands->domainForHost($request->getHost());
        } catch (Throwable) {
            // Pre-migration / installer: never fence anybody out.
            return $next($request);
        }

        if ($domain === null) {
            return $next($request);
        }

        if (! $this->columnsExist($domain)) {
            return $next($request);
        }

        if (! $domain->panel_enabled) {
            abort(404);
        }

        if (! $domain->panel_restricted) {
            return $next($request);
        }

        $customer = Auth::guard('customer')->user();

        if (! $customer instanceof Customer) {
            // Not signed in yet: the login page itself stays reachable, and
            // the check bites the moment a session exists. Refusing here
            // would make the brand's own login page unusable.
            return $next($request);
        }

        if ($this->belongsHere($customer, $domain)) {
            return $next($request);
        }

        Auth::guard('customer')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = __('themes.domains.panel.not_your_domain');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        return redirect()->to('/customer/login')->with('status', $message);
    }

    private function belongsHere(Customer $customer, Domain $domain): bool
    {
        // A single-tenant agency domain admits exactly that tenant.
        if ($domain->customer_id !== null) {
            return (int) $customer->getKey() === (int) $domain->customer_id;
        }

        return (int) $customer->domain_id === (int) $domain->getKey();
    }

    /**
     * The columns arrive in a WP-46 migration; an install that has not run it
     * yet must keep working rather than fataling on a missing attribute.
     */
    private function columnsExist(Domain $domain): bool
    {
        return $domain->getAttribute('panel_enabled') !== null
            || $domain->getAttribute('panel_restricted') !== null;
    }
}
