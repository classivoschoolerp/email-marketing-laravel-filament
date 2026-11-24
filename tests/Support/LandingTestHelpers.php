<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Testing\TestResponse;
use Throwable;

/**
 * Helpers for the WP-34 landing-page tests.
 *
 * Cookie replay deserves the explanation: the §1.8 sticky-variant and
 * unique-visitor guarantees are only meaningful ACROSS requests, so the tests
 * have to behave like a browser and send back what the previous response set.
 *
 * Laravel's test client re-encrypts everything passed to `withCookie()`, and
 * the values on a real response have already been encrypted by
 * EncryptCookies — handing them straight back would double-encrypt them and
 * the app would read a value it never wrote. So the value is decrypted (and
 * its name-bound prefix stripped) first, leaving exactly the plaintext the
 * browser holds.
 */
final class LandingTestHelpers
{
    /** Replay every cookie a response set onto the next request. */
    public static function replayCookies(TestResponse $response, object $test): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $test->withCookie($cookie->getName(), self::plain((string) $cookie->getValue()));
        }
    }

    /** The plaintext a browser would store for one Set-Cookie value. */
    public static function plain(string $value): string
    {
        try {
            return CookieValuePrefix::remove(decrypt($value, false));
        } catch (Throwable) {
            // Already plaintext (a cookie excluded from encryption).
            return $value;
        }
    }
}
