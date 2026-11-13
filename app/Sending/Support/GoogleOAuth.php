<?php

declare(strict_types=1);

namespace App\Sending\Support;

use App\Models\DeliveryServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google OAuth2 helper for the gmail-oauth driver (spec §2.9.2 F-2.9.2-017).
 *
 * The consent flow happens once in the UI (client id/secret + authorization
 * code → refresh token stored on the server row); at send time we exchange
 * the long-lived refresh token for a short-lived access token against
 * Google's token endpoint and cache it until just before expiry.
 *
 * Column mapping on the DeliveryServer row:
 *   config.client_id → OAuth client id (public)
 *   api_key          → OAuth client secret (encrypted at rest)
 *   password         → refresh token (encrypted at rest)
 *   username         → the Gmail / Workspace mailbox to send as
 */
class GoogleOAuth
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const SCOPE = 'https://mail.google.com/';

    /**
     * SASL XOAUTH2 initial client response (Gmail XOAUTH2 protocol doc):
     * base64("user=" {email} ^A "auth=Bearer " {token} ^A ^A).
     */
    public static function xoauth2String(string $email, string $accessToken): string
    {
        return base64_encode('user='.$email."\x01auth=Bearer ".$accessToken."\x01\x01");
    }

    /**
     * Exchange the refresh token for an access token (cached until ~60s
     * before expiry).
     *
     * @throws RuntimeException when Google rejects the exchange
     */
    public function accessToken(DeliveryServer $server): string
    {
        $cacheKey = 'sending:gmail-oauth:access-token:'.$server->id;

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => (string) $server->configValue('client_id'),
            'client_secret' => (string) $server->api_key,
            'refresh_token' => (string) $server->password,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException(
                'Google OAuth token exchange failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $token = (string) $response->json('access_token');
        $expiresIn = max(60, (int) $response->json('expires_in', 3600));

        Cache::put($cacheKey, $token, now()->addSeconds($expiresIn - 60));

        return $token;
    }
}
