<?php

declare(strict_types=1);

use App\Models\DeliveryServer;
use App\Sending\Drivers\GmailOauthDriver;
use App\Sending\Message;
use App\Sending\Support\GoogleOAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * F-2.9.2-017 — Gmail/Workspace OAuth: refresh-token exchange against
 * Google's token endpoint (Http::fake) + transport credential wiring.
 * The XOAUTH2 SASL string itself is unit-tested against the documented
 * Google vector in tests/Unit/Sending/XOAuth2Test.php.
 */

function gmailServer(array $attributes = []): DeliveryServer
{
    $server = DeliveryServer::factory()->createOne(array_merge([
        'customer_id' => null,
        'type' => 'gmail-oauth',
        'username' => 'sender@workspace.example',
        'api_key' => 'oauth-client-secret',
        'password' => 'oauth-refresh-token',
        'config' => ['client_id' => 'client-1.apps.googleusercontent.com'],
    ], $attributes));

    return $server;
}

it('exchanges the refresh token for an access token at the documented endpoint', function (): void {
    Http::fake([GoogleOAuth::TOKEN_URL => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3600])]);

    $token = app(GoogleOAuth::class)->accessToken(gmailServer());

    expect($token)->toBe('ya29.token');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://oauth2.googleapis.com/token'
        && $r['grant_type'] === 'refresh_token'
        && $r['client_id'] === 'client-1.apps.googleusercontent.com'
        && $r['client_secret'] === 'oauth-client-secret'
        && $r['refresh_token'] === 'oauth-refresh-token');
});

it('caches the access token until expiry', function (): void {
    Http::fake([GoogleOAuth::TOKEN_URL => Http::response(['access_token' => 'ya29.cached', 'expires_in' => 3600])]);

    $server = gmailServer();
    $oauth = app(GoogleOAuth::class);

    expect($oauth->accessToken($server))->toBe('ya29.cached')
        ->and($oauth->accessToken($server))->toBe('ya29.cached');

    Http::assertSentCount(1);
});

it('wires the token into the SMTP transport as the XOAUTH2 password', function (): void {
    Http::fake([GoogleOAuth::TOKEN_URL => Http::response(['access_token' => 'ya29.transport', 'expires_in' => 3600])]);

    $driver = app(GmailOauthDriver::class);
    $transport = $driver->buildTransport(gmailServer());

    expect($transport->getStream()->getHost())->toBe('smtp.gmail.com')
        ->and($transport->getStream()->getPort())->toBe(587)
        ->and($transport->getUsername())->toBe('sender@workspace.example')
        ->and($transport->getPassword())->toBe('ya29.transport');
});

it('classifies a revoked grant as permanent', function (): void {
    Http::fake([GoogleOAuth::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

    $result = app(GmailOauthDriver::class)->send(
        new Message(fromEmail: 'sender@workspace.example', to: ['x@y.z'], subject: 's', text: 'b'),
        gmailServer(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->transient)->toBeFalse()
        ->and($result->error)->toContain('invalid_grant');
});

it('classifies a Google outage as transient', function (): void {
    Http::fake([GoogleOAuth::TOKEN_URL => Http::response('upstream error', 503)]);

    $result = app(GmailOauthDriver::class)->send(
        new Message(fromEmail: 'sender@workspace.example', to: ['x@y.z'], subject: 's', text: 'b'),
        gmailServer(),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->transient)->toBeTrue();
});

it('refuses to send with incomplete oauth config', function (): void {
    Http::fake();

    $result = app(GmailOauthDriver::class)->send(
        new Message(fromEmail: 'a@b.c', to: ['x@y.z'], subject: 's', text: 'b'),
        gmailServer(['password' => null]),
    );

    expect($result->ok)->toBeFalse()->and($result->transient)->toBeFalse();
    Http::assertNothingSent();
});
