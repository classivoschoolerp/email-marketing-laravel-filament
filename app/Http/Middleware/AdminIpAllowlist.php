<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Providers\ComplianceServiceProvider;
use App\Services\Settings\SettingsReader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Allowed admin IPs (spec §3.25.15 F-3.25.15-005, WP-22).
 *
 * Attached to the admin panel's middleware stack (before authentication) by
 * {@see ComplianceServiceProvider}. An empty allowlist means
 * "no restriction" — the safe default, and the only sane behaviour for an
 * operator who has not configured the list yet.
 *
 * Entries accept single IPv4/IPv6 addresses and CIDR ranges; matching is
 * delegated to Symfony's IpUtils, the same helper Laravel's TrustProxies
 * uses. A blocked request gets a bare 403 (never a hint about the list).
 */
class AdminIpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $allowed = SettingsReader::security()->adminAllowedIps();
        } catch (Throwable) {
            // Settings not migrated yet (installer / first boot) — fail open,
            // exactly like SiteOffline (D-24), so nobody is locked out of the
            // panel that configures the allowlist.
            return $next($request);
        }

        if ($allowed === []) {
            return $next($request);
        }

        $ip = (string) $request->ip();

        if ($ip !== '' && IpUtils::checkIp($ip, $allowed)) {
            return $next($request);
        }

        abort(Response::HTTP_FORBIDDEN, __('settings.security.ip_blocked'));
    }
}
