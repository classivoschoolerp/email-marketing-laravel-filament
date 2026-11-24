<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\LandingPage;
use App\Models\LandingPageVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * A/B variant serving with sticky cookie assignment (spec §2.6 F-2.6-004,
 * §1.8 F-1.8-002).
 *
 * Stickiness is the whole point: a visitor who reloads, comes back tomorrow,
 * or converts on a second visit MUST see the arm they were first shown —
 * otherwise the conversion is attributed to an arm that never persuaded
 * anyone and the test measures noise. The assignment therefore lives in a
 * first-party cookie keyed by the page uid, and is re-read before any random
 * draw happens.
 *
 * Precedence, in order:
 *   1. a declared WINNER takes 100% of traffic — the test is over, and a
 *      concluded test must not keep serving losers;
 *   2. the visitor's existing cookie, if it still points at an enabled arm;
 *   3. a weighted draw across the enabled arms.
 *
 * `weight` is a raw share, not a percentage: the draw is over the sum of the
 * enabled weights, so disabling one arm mid-test does not silently give the
 * remaining traffic to whichever arm happens to sort first.
 */
class LandingVariantAssigner
{
    public const COOKIE_PREFIX = 'emp_lpv_';

    /** Sticky for a month — long enough to cover a considered purchase. */
    public const COOKIE_MINUTES = 60 * 24 * 30;

    public function cookieName(LandingPage $page): string
    {
        return self::COOKIE_PREFIX.$page->uid;
    }

    /**
     * The arm to serve. NULL means "the page's own content" — either the test
     * is off or it has no enabled arms.
     *
     * Queues the assignment cookie when the visitor did not already carry it,
     * so the next request lands on step 2 above.
     */
    public function assign(LandingPage $page, Request $request): ?LandingPageVariant
    {
        $variants = $page->publicVariants();

        if (! $page->ab_enabled || $variants === []) {
            return null;
        }

        foreach ($variants as $variant) {
            if ($variant->is_winner) {
                $this->remember($page, $variant);

                return $variant;
            }
        }

        $existing = $this->fromCookie($request, $page, $variants);

        if ($existing !== null) {
            return $existing;
        }

        $chosen = $this->draw($variants);

        if ($chosen !== null) {
            $this->remember($page, $chosen);
        }

        return $chosen;
    }

    /** The arm a request is already assigned to, without assigning one. */
    public function current(LandingPage $page, Request $request): ?LandingPageVariant
    {
        return $this->fromCookie($request, $page, $page->publicVariants());
    }

    /**
     * @param  list<LandingPageVariant>  $variants
     */
    private function fromCookie(Request $request, LandingPage $page, array $variants): ?LandingPageVariant
    {
        $uid = (string) $request->cookie($this->cookieName($page), '');

        if ($uid === '') {
            return null;
        }

        foreach ($variants as $variant) {
            if ($variant->uid === $uid) {
                return $variant;
            }
        }

        // The cookie points at a deleted or disabled arm: fall through to a
        // fresh draw rather than serving content the operator switched off.
        return null;
    }

    /**
     * @param  list<LandingPageVariant>  $variants
     */
    private function draw(array $variants): ?LandingPageVariant
    {
        $total = 0;

        foreach ($variants as $variant) {
            $total += max(0, (int) $variant->weight);
        }

        // All weights zeroed: an even split is the only honest reading, and
        // it beats serving nothing.
        if ($total <= 0) {
            return $variants[random_int(0, count($variants) - 1)];
        }

        $ticket = random_int(1, $total);
        $cursor = 0;

        foreach ($variants as $variant) {
            $cursor += max(0, (int) $variant->weight);

            if ($ticket <= $cursor) {
                return $variant;
            }
        }

        return $variants[count($variants) - 1];
    }

    private function remember(LandingPage $page, LandingPageVariant $variant): void
    {
        Cookie::queue(Cookie::make(
            $this->cookieName($page),
            $variant->uid,
            self::COOKIE_MINUTES,
            null,
            null,
            null,
            true,
            false,
            'lax',
        ));
    }
}
