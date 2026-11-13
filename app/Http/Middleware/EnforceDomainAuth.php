<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Branding\BrandContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-domain registration and login (spec §6.4 F-6.4-003).
 *
 * A brand may be a closed portal (login only, accounts created by the
 * operator) or a public sign-up site, and the two switches are independent.
 * Both live on the `domains` row; a request that reaches the app host or an
 * unmapped host is unaffected, so a single-brand installation never notices
 * this middleware exists.
 *
 * Implemented as a ROUTE-NAME gate rather than by editing the WP-02
 * registration controller or the WP-02 customer login page: those files
 * belong to another work package, and a brand rule that only some auth
 * entry points respect would be worse than none.
 *
 * A disabled entry point answers 404, not 403. On a brand that never offered
 * sign-up, "this page does not exist" is the truth, and a 403 would confirm
 * to a scanner that accounts exist behind that host.
 */
class EnforceDomainAuth
{
    /** Route names guarded by `domains.registration_enabled`. */
    private const REGISTRATION_ROUTES = [
        'register',
        'register.store',
        'register.pending',
    ];

    /** Route names guarded by `domains.login_enabled`. */
    private const LOGIN_ROUTES = [
        'filament.customer.auth.login',
        'customer.two-factor',
        'customer.two-factor.verify',
        'sso.social.redirect',
        'sso.social.callback',
    ];

    public function __construct(private readonly BrandContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $domain = $this->context->current()->domain;

        if ($domain === null) {
            return $next($request);
        }

        $name = (string) $request->route()?->getName();

        if ($name === '') {
            return $next($request);
        }

        if (in_array($name, self::REGISTRATION_ROUTES, true) && ! $domain->registration_enabled) {
            abort(404);
        }

        if (in_array($name, self::LOGIN_ROUTES, true) && ! $domain->login_enabled) {
            abort(404);
        }

        return $next($request);
    }
}
