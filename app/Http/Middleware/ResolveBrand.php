<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Providers\BrandingServiceProvider;
use App\Services\Branding\BrandContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request host to a brand (spec §6.4 F-6.4-001/002).
 *
 * Pushed onto the `web` group by
 * {@see BrandingServiceProvider} rather than written into
 * bootstrap/app.php, so the framework kernel stays untouched.
 *
 * Two things happen here and nothing else:
 *  - the brand is resolved and parked in the request-scoped BrandContext;
 *  - when the matched brand asks for it, an insecure request is redirected to
 *    https. That is an application-level redirect, NOT certificate handling:
 *    nothing in this package obtains, renews or installs a certificate, and
 *    the brand's `ssl_status` is an observation made by DomainSslInspector,
 *    never a claim made here (F-6.4-004).
 *
 * The middleware is deliberately fail-open: an unresolvable host, a missing
 * `domains` table (installer) or any read error yields the install-wide
 * brand. A branding layer must never be able to take the site down.
 */
class ResolveBrand
{
    public function __construct(private readonly BrandContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $brand = $this->context->setFromRequest($request);

        // Every Blade template gets `$empBrand` without resolving anything
        // itself — one share per request beats a wildcard view composer.
        View::share('empBrand', $brand);

        $domain = $brand->domain;

        // Only safe methods are redirected: a 301 on a POST would silently
        // drop the body in most clients, which is a worse failure than
        // serving the write over the scheme the caller chose.
        if ($domain !== null && $domain->force_https && ! $request->secure()
            && in_array($request->method(), ['GET', 'HEAD'], true)) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
