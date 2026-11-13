<?php

declare(strict_types=1);

namespace App\Services\Tracking;

/**
 * HMAC-SHA256 signatures for tracked URLs (spec §1.7 F-1.7-008). The hash in
 * `/campaigns/{uid}/track-url/{sub_uid}/{url_hash}` is the HMAC of the
 * destination URL keyed with the app key, so a recipient (or attacker)
 * cannot forge a redirect target or tamper with an existing one — a modified
 * hash simply matches no campaign_track_urls row and verify() fails.
 */
final class UrlSigner
{
    /** Deterministic HMAC hash for a destination URL (64 hex chars). */
    public function hash(string $url): string
    {
        return hash_hmac('sha256', $url, $this->key());
    }

    /** Constant-time check that a hash belongs to the given URL. */
    public function verify(string $url, string $hash): bool
    {
        return hash_equals($this->hash($url), $hash);
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }
}
