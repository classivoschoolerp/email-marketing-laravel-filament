<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\LandingPage;
use App\Models\TrackingDomain;
use App\Services\Domains\TrackingDomainVerifier;
use App\Services\Tracking\TrackingUrlBuilder;

/**
 * Where a landing page lives (spec §2.6 F-2.6-006, §1.8 F-1.8-001).
 *
 * Custom domains reuse §2.9.8 tracking domains wholesale — the same
 * `tracking_domains` row, the same CNAME target, the same
 * {@see TrackingDomainVerifier}, and the same
 * {@see TrackingUrlBuilder} scheme rule (https only once `ssl_status` is
 * `active`). Nothing in this module issues a certificate: the SSL column is
 * surfaced exactly as the verifier left it (F-2.9.8-005 auto-HTTPS is still
 * unbuilt, and pretending otherwise would put customers on a dead https URL).
 *
 * Host resolution is also the public route's tenancy fence: a page answers on
 * the app host and on ITS OWN assigned domain, never on another tenant's
 * domain — see {@see servableOnHost()}.
 */
class LandingPageUrl
{
    /** Path prefix of every public landing URL. */
    public const PREFIX = 'p';

    public function __construct(private readonly TrackingUrlBuilder $tracking) {}

    /** The canonical public URL of a page. */
    public function url(LandingPage $page): string
    {
        return $this->base($page).'/'.self::PREFIX.'/'.$page->slug;
    }

    /** The URL a submitted form posts to. */
    public function submitUrl(LandingPage $page): string
    {
        return $this->url($page).'/submit';
    }

    public function thanksUrl(LandingPage $page): string
    {
        return $this->url($page).'/thank-you';
    }

    /**
     * The <link rel="canonical"> value: the customer's override when set,
     * otherwise the page's own canonical URL (F-2.6-007).
     */
    public function canonical(LandingPage $page): string
    {
        $override = trim((string) $page->canonical_url);

        return $override !== '' ? $override : $this->url($page);
    }

    /** Scheme + host (no trailing slash) the page is published on. */
    public function base(LandingPage $page): string
    {
        $domain = $page->publicTrackingDomain();

        if ($domain !== null && $domain->isVerified()) {
            return $this->tracking->baseUrl($domain);
        }

        $subdomain = $this->normalizeSubdomain($page->subdomain);

        if ($subdomain !== null) {
            $appUrl = rtrim((string) config('app.url'), '/');
            $scheme = str_starts_with($appUrl, 'https://') ? 'https' : 'http';

            return $scheme.'://'.$subdomain.'.'.$this->appHost();
        }

        return rtrim((string) config('app.url'), '/');
    }

    /**
     * May this page be served for a request arriving on $host?
     *
     * - the app host and the page's own subdomain always serve it;
     * - a host that is a KNOWN tracking domain serves only the pages assigned
     *   to that domain — so tenant A's page is a 404 on tenant B's domain
     *   even though the slug is globally unique;
     * - an unknown host (a wildcard DNS record someone pointed at the app,
     *   a reverse proxy, `localhost` in tests) is treated as the app host.
     */
    public function servableOnHost(LandingPage $page, ?string $host): bool
    {
        $host = mb_strtolower(trim((string) $host));

        if ($host === '' || $host === $this->appHost()) {
            return true;
        }

        $assigned = $page->publicTrackingDomain();

        if ($assigned !== null && mb_strtolower((string) $assigned->name) === $host) {
            return true;
        }

        $subdomain = $this->normalizeSubdomain($page->subdomain);

        if ($subdomain !== null && $host === $subdomain.'.'.$this->appHost()) {
            return true;
        }

        // A host that belongs to SOME tracking domain but not this page's.
        return ! TrackingDomain::withoutTenancy()->whereRaw('LOWER(name) = ?', [$host])->exists();
    }

    /**
     * SSL state to show in the panel: whatever the §2.9.8 tracking-domain row
     * last recorded, or `none` when no custom domain is assigned.
     *
     * NEVER invented and never derived from "the domain verified, so HTTPS
     * probably works". Nothing in this product issues a certificate
     * (F-2.9.8-005 auto-HTTPS is unbuilt), and a fabricated `active` would
     * send customers to advertise an https URL that fails to load.
     */
    public function sslStatus(int|string|null $trackingDomainId): string
    {
        if ($trackingDomainId === null || (int) $trackingDomainId <= 0) {
            return 'none';
        }

        $status = TrackingDomain::withoutTenancy()
            ->whereKey((int) $trackingDomainId)
            ->value('ssl_status');

        return is_string($status) && $status !== '' ? $status : 'none';
    }

    /** Host part of `app.url` — the platform's own host. */
    public function appHost(): string
    {
        return mb_strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
    }

    /** A single DNS label, or null when unusable. */
    public function normalizeSubdomain(mixed $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === '' || preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
