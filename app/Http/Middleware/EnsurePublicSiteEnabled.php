<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings\SettingsReader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The §1.1 F-1.1-003 switch: "enable/disable public site entirely (pure
 * self-hosted mode)".
 *
 * Applied ONLY to the marketing site and the CMS. It deliberately does NOT
 * cover the §1.5–§1.8 subscriber-facing pages (subscribe, unsubscribe,
 * preference centre, web view, landing pages): those are part of sending
 * email, and disabling them would break CAN-SPAM/GDPR obligations for a
 * self-hosted operator who merely did not want a marketing homepage.
 *
 * Turned off, the routes 404 rather than redirect. A redirect to /customer
 * would tell a scanner that a SaaS control panel lives here; a 404 says the
 * page does not exist, which — in self-hosted mode — is true.
 */
class EnsurePublicSiteEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $enabled = SettingsReader::marketing()->publicSiteEnabled();
        } catch (Throwable) {
            // Settings not migrated (installer) — fail CLOSED here, unlike
            // SiteOffline (D-24). A missing setting must not accidentally
            // publish a marketing site an operator never enabled.
            $enabled = false;
        }

        abort_unless($enabled, 404);

        return $next($request);
    }
}
