<?php

declare(strict_types=1);

use App\Http\Middleware\ForceHttps;
use App\Models\EmailList;
use App\Settings\SecuritySettings;

/*
 * WP-22 — F-3.25.15-004: force HTTPS + HSTS via the ForceHttps middleware
 * pushed onto the `web` group by ComplianceServiceProvider.
 */

beforeEach(function (): void {
    $this->list = EmailList::factory()->create(['visible' => true, 'status' => EmailList::STATUS_ACTIVE]);
    $this->url = route('lists.public.subscribe', ['list_uid' => $this->list->uid]);
});

it('registers the force-https middleware on the web group', function (): void {
    expect(app('router')->getMiddlewareGroups()['web'] ?? [])->toContain(ForceHttps::class);
});

it('leaves plain http alone while the setting is off', function (): void {
    $this->get($this->url)->assertOk()->assertHeaderMissing('Strict-Transport-Security');
});

// F-3.25.15-004 (force HTTPS)
it('redirects http requests to https when force_https is on', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->force_https = true;
    $settings->save();

    $response = $this->get($this->url);

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toStartWith('https://');
});

// F-3.25.15-004 (HSTS header)
it('stamps the Strict-Transport-Security header when hsts is on', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->hsts_enabled = true;
    $settings->hsts_max_age = 63072000;
    $settings->hsts_include_subdomains = true;
    $settings->hsts_preload = true;
    $settings->save();

    $this->get($this->url)
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
});

it('emits a bare max-age when the sub-flags are off', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->hsts_enabled = true;
    $settings->hsts_max_age = 3600;
    $settings->save();

    $this->get($this->url)->assertHeader('Strict-Transport-Security', 'max-age=3600');
});
