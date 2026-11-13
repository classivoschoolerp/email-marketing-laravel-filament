<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Settings\CommonSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * API offline mode (spec §3.25.1, F-3.25.1-008).
 *
 * Attached to the `api` middleware group. When `common.api_offline` is enabled
 * every API request receives a 503 JSON response; IPs on the maintenance
 * allowlist keep access. The API route group itself ships with WP-19.
 */
class ApiOffline
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $settings = app(CommonSettings::class);
            $offline = $settings->api_offline;
            $allowedIps = $settings->maintenance_allowed_ips;
        } catch (Throwable) {
            // Settings not migrated yet (installer / first boot) — never lock the app out.
            return $next($request);
        }

        if (! $offline) {
            return $next($request);
        }

        if (in_array($request->ip(), $allowedIps, true)) {
            return $next($request);
        }

        return response()->json(
            ['message' => __('settings.offline.api_message')],
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
