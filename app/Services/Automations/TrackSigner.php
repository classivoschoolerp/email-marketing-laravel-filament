<?php

declare(strict_types=1);

namespace App\Services\Automations;

/**
 * HMAC signatures for the WP-21 open-pixel / click-redirect endpoints
 * (automation emails aren't campaigns, so the WP-09 tracking endpoints
 * don't apply). Click signatures cover the destination URL to block open
 * redirects.
 */
class TrackSigner
{
    public function signOpen(string $sendUid): string
    {
        return $this->hmac('open:'.$sendUid);
    }

    public function signClick(string $sendUid, string $url): string
    {
        return $this->hmac('click:'.$sendUid.':'.$url);
    }

    public function validOpen(string $sendUid, string $signature): bool
    {
        return hash_equals($this->signOpen($sendUid), $signature);
    }

    public function validClick(string $sendUid, string $url, string $signature): bool
    {
        return hash_equals($this->signClick($sendUid, $url), $signature);
    }

    private function hmac(string $payload): string
    {
        $key = (string) config('app.key');

        return substr(hash_hmac('sha256', 'automation-track:'.$payload, $key), 0, 40);
    }
}
