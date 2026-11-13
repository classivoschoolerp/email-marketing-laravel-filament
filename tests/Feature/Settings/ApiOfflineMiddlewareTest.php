<?php

declare(strict_types=1);

use App\Settings\CommonSettings;
use Illuminate\Support\Facades\Route;

function setApiOffline(bool $offline, array $allowedIps = []): void
{
    $settings = app(CommonSettings::class);
    $settings->api_offline = $offline;
    $settings->maintenance_allowed_ips = $allowedIps;
    $settings->save();
}

beforeEach(function (): void {
    // The real API route group ships with WP-19; a probe route on the `api`
    // middleware group exercises the middleware exactly as WP-19's routes will.
    Route::middleware('api')->get('/api/wp04-ping', fn (): array => ['pong' => true]);
});

it('serves API routes while the API is online', function (): void {
    setApiOffline(false);

    $this->getJson('/api/wp04-ping')->assertOk()->assertJson(['pong' => true]);
});

it('returns 503 JSON on API routes while the API is offline', function (): void {
    setApiOffline(true);

    $this->getJson('/api/wp04-ping')
        ->assertServiceUnavailable()
        ->assertJson(['message' => __('settings.offline.api_message')]);
});

it('lets allowlisted IPs use the API while offline', function (): void {
    setApiOffline(true, allowedIps: ['127.0.0.1']);

    $this->getJson('/api/wp04-ping')->assertOk()->assertJson(['pong' => true]);
});

it('keeps the API online while only the site is offline', function (): void {
    $settings = app(CommonSettings::class);
    $settings->site_offline = true;
    $settings->save();

    $this->getJson('/api/wp04-ping')->assertOk()->assertJson(['pong' => true]);

    $this->get('/')->assertServiceUnavailable();
});

it('keeps the site online while only the API is offline', function (): void {
    setApiOffline(true);

    $this->get('/')->assertOk();
});
