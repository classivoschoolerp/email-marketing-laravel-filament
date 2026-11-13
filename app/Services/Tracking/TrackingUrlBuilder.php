<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Models\TrackingDomain;

/**
 * Host-aware base URL for open/click/unsubscribe tracking links
 * (spec §2.9.8, WP-15). A campaign (or delivery server) assigned a VERIFIED
 * tracking domain gets its links generated on that host; otherwise app.url.
 * The tracking routes themselves answer on any Host (Laravel default), so no
 * route changes are needed — WP-13's URL rewriting consumes this service
 * when present. Plain class, container-autowired — no provider binding.
 *
 * Scheme: https once ssl_status is 'active'; plain http until then (SSL
 * provisioning is the §2.9.8 placeholder; auto-HTTPS is P2).
 */
class TrackingUrlBuilder
{
    public const SSL_ACTIVE = 'active';

    /** Base URL (no trailing slash) for an optional tracking domain. */
    public function baseUrl(?TrackingDomain $domain): string
    {
        if ($domain === null || ! $domain->isVerified()) {
            return rtrim((string) config('app.url'), '/');
        }

        $scheme = $domain->ssl_status === self::SSL_ACTIVE ? 'https' : 'http';

        return $scheme.'://'.$domain->name;
    }

    /** Base URL for a tracking-domain id (unknown/unverified id → app.url). */
    public function forTrackingDomainId(int|string|null $id): string
    {
        if ($id === null || (int) $id <= 0) {
            return $this->baseUrl(null);
        }

        return $this->baseUrl(TrackingDomain::withoutTenancy()->find((int) $id));
    }

    /**
     * Base URL for a campaign-shaped object exposing tracking_domain_id
     * (the WP-13 seam — accepts the Campaign model or any stdClass row).
     */
    public function forCampaign(?object $campaign): string
    {
        return $this->forTrackingDomainId($campaign?->tracking_domain_id ?? null);
    }

    /** Rebase an absolute app-url path onto the tracking base. */
    public function rebase(string $url, ?TrackingDomain $domain): string
    {
        return $this->rebaseOnto($url, $this->baseUrl($domain));
    }

    /**
     * Rebase an absolute app-url onto an already-resolved base (avoids a second
     * tracking_domains lookup when the caller resolved the base once per send).
     */
    public function rebaseOnto(string $url, string $base): string
    {
        $appBase = rtrim((string) config('app.url'), '/');

        if ($base === $appBase || ! str_starts_with($url, $appBase)) {
            return $url;
        }

        return $base.substr($url, strlen($appBase));
    }
}
