<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Providers\ComplianceServiceProvider;
use App\Services\Settings\SettingsReader;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Force HTTPS + HSTS (spec §3.25.15 F-3.25.15-004, WP-22).
 *
 * Runs on the `web` group (pushed by
 * {@see ComplianceServiceProvider}) so it covers the public
 * frontend, the auth module and both Filament panels.
 *
 *  - force_https ON  → plain-http GET/HEAD requests are 301-redirected to
 *    the https URL and every generated URL is forced to https for the rest
 *    of the request. Non-idempotent verbs are not redirected (a 301 would
 *    silently drop the body); they simply get the forced URL scheme.
 *  - hsts_enabled ON → Strict-Transport-Security is stamped on the response.
 *    Per RFC 6797 the header is only meaningful over a secure transport, so
 *    it is emitted on https responses (and in the testing environment, where
 *    there is no TLS terminator to make the request secure).
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $security = SettingsReader::security();
            $force = $security->forceHttps();
            $hsts = $security->hstsEnabled();
            $header = $security->hstsHeader();
        } catch (Throwable) {
            // Settings not migrated yet (installer / first boot).
            return $next($request);
        }

        if ($force) {
            URL::forceScheme('https');

            if (! $request->isSecure() && $request->isMethodCacheable()) {
                return redirect()->to(
                    'https://'.$request->getHttpHost().$request->getRequestUri(),
                    Response::HTTP_MOVED_PERMANENTLY,
                );
            }
        }

        $response = $next($request);

        if ($hsts && ($request->isSecure() || app()->environment('testing'))) {
            $response->headers->set('Strict-Transport-Security', $header);
        }

        return $response;
    }
}
