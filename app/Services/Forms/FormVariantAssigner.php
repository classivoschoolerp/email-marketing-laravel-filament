<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * A/B arm serving for §2.7 forms (F-2.7-013), the same machine
 * {@see \App\Services\Landing\LandingVariantAssigner} runs for landing pages.
 *
 * Stickiness is the whole point: a visitor who reloads the host page, or comes
 * back tomorrow and only then fills the form in, MUST see the arm they were
 * first shown — otherwise the conversion is credited to an arm that never
 * persuaded anyone and the test measures noise.
 *
 * The assignment lives in a FIRST-PARTY COOKIE keyed by the form uid and is
 * never a session value. A §2.7 public request must not consult tenancy or a
 * session at all (D-95); a cookie also survives the fact that the form is
 * usually rendered inside somebody else's page.
 *
 * Precedence, in order:
 *   1. a declared WINNER takes 100% of traffic — a concluded test must not
 *      keep serving losers;
 *   2. the visitor's existing cookie, if it still points at an enabled arm;
 *   3. a weighted draw across the enabled arms.
 *
 * `weight` is a raw share, not a percentage: the draw is over the sum of the
 * enabled weights, so disabling one arm mid-test does not silently hand the
 * remaining traffic to whichever arm happens to sort first.
 */
class FormVariantAssigner
{
    public const COOKIE_PREFIX = 'emp_fv_';

    /** Sticky for a month — long enough to cover a considered sign-up. */
    public const COOKIE_MINUTES = 60 * 24 * 30;

    public function cookieName(Form $form): string
    {
        return self::COOKIE_PREFIX.$form->uid;
    }

    /**
     * The arm to serve. NULL means "the form's own content" — either the test
     * is off or it has no enabled arms.
     *
     * Queues the assignment cookie when the visitor did not already carry it,
     * so the next request lands on step 2 above.
     */
    public function assign(Form $form, Request $request): ?FormVariant
    {
        $variants = $form->publicVariants();

        if (! $form->ab_enabled || $variants === []) {
            return null;
        }

        foreach ($variants as $variant) {
            if ($variant->is_winner) {
                $this->remember($form, $variant);

                return $variant;
            }
        }

        $existing = $this->fromCookie($request, $form, $variants);

        if ($existing !== null) {
            return $existing;
        }

        $chosen = $this->draw($variants);

        if ($chosen !== null) {
            $this->remember($form, $chosen);
        }

        return $chosen;
    }

    /**
     * The arm a request is ALREADY assigned to, without assigning one.
     *
     * This is what the submit endpoint uses: a submission must be credited to
     * the arm the visitor actually saw, and drawing a fresh arm at submit time
     * would credit a random one.
     */
    public function current(Form $form, Request $request): ?FormVariant
    {
        if (! $form->ab_enabled) {
            return null;
        }

        return $this->fromCookie($request, $form, $form->publicVariants());
    }

    /**
     * @param  list<FormVariant>  $variants
     */
    private function fromCookie(Request $request, Form $form, array $variants): ?FormVariant
    {
        $uid = (string) $request->cookie($this->cookieName($form), '');

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
     * @param  list<FormVariant>  $variants
     */
    private function draw(array $variants): ?FormVariant
    {
        $total = 0;

        foreach ($variants as $variant) {
            $total += max(0, (int) $variant->weight);
        }

        // All weights zeroed: an even split is the only honest reading, and it
        // beats serving nothing.
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

    private function remember(Form $form, FormVariant $variant): void
    {
        Cookie::queue(Cookie::make(
            $this->cookieName($form),
            (string) $variant->uid,
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
