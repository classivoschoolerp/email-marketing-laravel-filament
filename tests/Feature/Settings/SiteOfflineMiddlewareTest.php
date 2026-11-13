<?php

declare(strict_types=1);

use App\Models\User;
use App\Settings\CommonSettings;

function setSiteOffline(bool $offline, string $message = 'Down for maintenance.', array $allowedIps = []): void
{
    $settings = app(CommonSettings::class);
    $settings->site_offline = $offline;
    $settings->site_offline_message = $message;
    $settings->maintenance_allowed_ips = $allowedIps;
    $settings->save();
}

it('serves the site normally while online', function (): void {
    setSiteOffline(false);

    $this->get('/')->assertOk();
});

it('blocks guests with a 503 and the configured message while offline', function (): void {
    setSiteOffline(true, 'We are upgrading the mail engine.');

    $this->get('/')
        ->assertServiceUnavailable()
        ->assertSee('We are upgrading the mail engine.');
});

it('returns the offline message as JSON when requested', function (): void {
    setSiteOffline(true, 'We are upgrading the mail engine.');

    $this->getJson('/')
        ->assertServiceUnavailable()
        ->assertJson(['message' => 'We are upgrading the mail engine.']);
});

it('lets allowlisted IPs through while offline', function (): void {
    setSiteOffline(true, allowedIps: ['127.0.0.1']);

    $this->get('/')->assertOk();
});

it('keeps the admin login reachable while offline', function (): void {
    setSiteOffline(true);

    $this->get('/admin/login')->assertOk();
});

it('keeps the admin panel usable for admins while offline', function (): void {
    setSiteOffline(true);

    $this->actingAs(User::factory()->create(), 'admin')
        ->get('/admin')
        ->assertOk();
});

it('keeps the health-check endpoint reachable while offline', function (): void {
    setSiteOffline(true);

    $this->get('/up')->assertOk();
});
