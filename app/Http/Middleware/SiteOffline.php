<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Settings\CommonSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Site offline mode (spec §3.25.1, F-3.25.1-007 / F-3.25.1-011).
 *
 * Runs globally. When `common.site_offline` is enabled every visitor receives a
 * 503 page carrying the configured message, except:
 *  - IPs on the maintenance allowlist,
 *  - the admin panel (and Livewire endpoints it depends on) so an administrator
 *    can always log in and turn the site back on,
 *  - the /up health-check endpoint.
 *
 * The API surface (/api/*) is governed by {@see ApiOffline} instead.
 */
class SiteOffline
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api', 'api/*')) {
            return $next($request);
        }

        try {
            $settings = app(CommonSettings::class);
            $offline = $settings->site_offline;
            $message = $settings->site_offline_message;
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

        if ($request->is('admin', 'admin/*', 'livewire/*', 'up')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response($this->renderOfflinePage($message), Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function renderOfflinePage(string $message): string
    {
        $title = e(__('settings.offline.site_title'));
        $body = e($message);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>{$title}</title>
                <style>
                    body { font-family: ui-sans-serif, system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f9fafb; color: #111827; }
                    main { max-width: 32rem; padding: 2rem; text-align: center; }
                    h1 { font-size: 1.25rem; }
                </style>
            </head>
            <body>
                <main>
                    <h1>{$title}</h1>
                    <p>{$body}</p>
                </main>
            </body>
            </html>
            HTML;
    }
}
