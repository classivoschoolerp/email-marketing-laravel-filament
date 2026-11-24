<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\FormSubmission;
use App\Models\LandingPage;
use App\Models\LandingPageConversion;
use App\Models\LandingPageVariant;
use App\Models\LandingPageVisit;
use App\Models\Subscriber;
use App\Services\Forms\FormAnalytics;
use App\Support\Privacy\IpAnonymizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Visit and conversion capture (spec §2.6 F-2.6-008, §1.8 F-1.8-003).
 *
 * UNIQUE VISITORS is the reason this is a row-per-visit table rather than the
 * daily counter rollup the §2.7 forms module uses: "unique" cannot be
 * reconstructed from a counter. The identity is a first-party cookie, and
 * what is stored is `sha256(cookie . app key)` — the raw cookie value never
 * reaches the database, so a leaked dump cannot be replayed as a visitor.
 *
 * IPs go through {@see IpAnonymizer::maybe()}, so the §3.25.16
 * anonymise-IPs setting governs this capture point exactly like the tracking
 * pixel and the §1.5 public pages. Source normalisation is delegated to the
 * §2.7 {@see FormAnalytics::normalizeSource()} so "by source" means the same
 * thing on a form report and on a landing report.
 */
class LandingPageTracking
{
    public const VISITOR_COOKIE = 'emp_lp_visitor';

    /** A year: long enough that a returning visitor still counts as one. */
    public const VISITOR_COOKIE_MINUTES = 60 * 24 * 365;

    /**
     * The stable, hashed identity of this visitor, minting + queueing the
     * cookie on a first visit.
     */
    public function visitorKey(Request $request): string
    {
        $raw = (string) $request->cookie(self::VISITOR_COOKIE, '');

        if ($raw === '' || ! preg_match('/^[0-9a-f]{32}$/', $raw)) {
            $raw = bin2hex(random_bytes(16));

            Cookie::queue(Cookie::make(
                self::VISITOR_COOKIE,
                $raw,
                self::VISITOR_COOKIE_MINUTES,
                null,
                null,
                null,
                true,
                false,
                'lax',
            ));
        }

        return hash('sha256', $raw.'|'.(string) config('app.key'));
    }

    /** Record one page view (F-2.6-008). */
    public function recordVisit(
        LandingPage $page,
        ?LandingPageVariant $variant,
        Request $request,
        string $visitorKey,
    ): LandingPageVisit {
        /** @var LandingPageVisit $visit */
        $visit = LandingPageVisit::withoutTenancy()->create([
            'customer_id' => $page->customer_id,
            'landing_page_id' => $page->id,
            'landing_page_variant_id' => $variant?->id,
            'visitor_key' => $visitorKey,
            'source' => $this->source($request),
            'referrer' => Str::limit((string) $request->headers->get('referer', ''), 2040, '') ?: null,
            'device' => $this->device($request),
            'ip' => IpAnonymizer::maybe($request->ip()),
            'user_agent' => Str::limit((string) $request->userAgent(), 990, '') ?: null,
            'converted' => false,
            'created_at' => now(),
        ]);

        return $visit;
    }

    /**
     * Record a conversion and flag the visit that produced it (F-2.6-008).
     *
     * The visit is looked up by visitor key rather than passed in, because a
     * submit arrives on a DIFFERENT request from the view that persuaded the
     * visitor — that is exactly what the sticky cookie is for.
     */
    public function recordConversion(
        LandingPage $page,
        ?LandingPageVariant $variant,
        Request $request,
        string $visitorKey,
        ?Subscriber $subscriber,
        ?FormSubmission $submission,
    ): LandingPageConversion {
        $visit = LandingPageVisit::withoutTenancy()
            ->where('landing_page_id', $page->id)
            ->where('visitor_key', $visitorKey)
            ->orderByDesc('id')
            ->first();

        if ($visit !== null) {
            $visit->forceFill(['converted' => true])->save();
        }

        /** @var LandingPageConversion $conversion */
        $conversion = LandingPageConversion::withoutTenancy()->create([
            'customer_id' => $page->customer_id,
            'landing_page_id' => $page->id,
            'landing_page_variant_id' => $variant?->id ?? $visit?->landing_page_variant_id,
            'landing_page_visit_id' => $visit?->id,
            'subscriber_id' => $subscriber?->id,
            'form_submission_id' => $submission?->id,
            'email' => $subscriber?->email ?? $submission?->email,
            'source' => $visit->source ?? $this->source($request),
            'visitor_key' => $visitorKey,
            'created_at' => now(),
        ]);

        return $conversion;
    }

    /** UTM-aware traffic source, normalised by the §2.7 rules. */
    private function source(Request $request): string
    {
        $explicit = trim((string) $request->query('utm_source', ''));

        if ($explicit !== '') {
            return FormAnalytics::normalizeSource($explicit);
        }

        return FormAnalytics::normalizeSource((string) $request->headers->get('referer', ''));
    }

    /** Coarse device bucket — the same three buckets forms report. */
    private function device(Request $request): ?string
    {
        $agent = mb_strtolower((string) $request->userAgent());

        if ($agent === '') {
            return null;
        }

        return match (true) {
            str_contains($agent, 'ipad') || str_contains($agent, 'tablet') => 'tablet',
            str_contains($agent, 'mobi') || str_contains($agent, 'android') => 'mobile',
            default => 'desktop',
        };
    }
}
