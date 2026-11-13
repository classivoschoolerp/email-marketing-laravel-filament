<?php

declare(strict_types=1);

use App\Services\License\LicenseService;
use App\Settings\LicenseSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const LICENSE_ENDPOINT = 'https://license.example.test/api/validate';

// D-09 — no endpoint configured = valid self-hosted mode
it('treats a missing endpoint as valid self-hosted mode', function (): void {
    config(['license.endpoint' => null]);
    Http::fake();

    $result = app(LicenseService::class)->validate('ANY-KEY');

    expect($result->valid())->toBeTrue()
        ->and($result->status)->toBe(LicenseSettings::STATUS_VALID)
        ->and($result->type)->toBe('self-hosted');

    Http::assertNothingSent();
});

// F-3.25.12-001 — valid key
it('validates a license key against the configured endpoint', function (): void {
    config(['license.endpoint' => LICENSE_ENDPOINT]);

    Http::fake([
        LICENSE_ENDPOINT => Http::response([
            'valid' => true,
            'type' => 'commercial',
            'expires_at' => '2027-12-31',
            'allowed_domains' => ['example.com', 'mail.example.com'],
            'renewal_url' => 'https://license.example.test/renew/ABC',
        ]),
    ]);

    $result = app(LicenseService::class)->validate('VALID-KEY');

    expect($result->valid())->toBeTrue()
        ->and($result->type)->toBe('commercial')
        ->and($result->expiresAt)->toBe('2027-12-31')
        ->and($result->allowedDomains)->toBe(['example.com', 'mail.example.com'])
        ->and($result->renewalUrl)->toBe('https://license.example.test/renew/ABC');

    Http::assertSent(fn ($request): bool => $request->url() === LICENSE_ENDPOINT
        && $request['license_key'] === 'VALID-KEY'
        && is_string($request['domain']));
});

// F-3.25.12-001 — rejected key
it('reports an invalid license key', function (): void {
    config(['license.endpoint' => LICENSE_ENDPOINT]);

    Http::fake([
        LICENSE_ENDPOINT => Http::response(['valid' => false, 'message' => 'Key revoked']),
    ]);

    $result = app(LicenseService::class)->validate('REVOKED-KEY');

    expect($result->valid())->toBeFalse()
        ->and($result->status)->toBe(LicenseSettings::STATUS_INVALID)
        ->and($result->message)->toBe('Key revoked');
});

it('reports a missing key as invalid when an endpoint is configured', function (): void {
    config(['license.endpoint' => LICENSE_ENDPOINT]);
    Http::fake();

    expect(app(LicenseService::class)->validate(null)->status)->toBe(LicenseSettings::STATUS_INVALID);

    Http::assertNothingSent();
});

// D-09 — unreachable server must not lock the app
it('reports an unreachable license server', function (): void {
    config(['license.endpoint' => LICENSE_ENDPOINT]);

    Http::fake(function (): void {
        throw new ConnectionException('Connection refused');
    });

    expect(app(LicenseService::class)->validate('ANY')->status)
        ->toBe(LicenseSettings::STATUS_UNREACHABLE);
});

it('treats a server error response as unreachable', function (): void {
    config(['license.endpoint' => LICENSE_ENDPOINT]);

    Http::fake([LICENSE_ENDPOINT => Http::response('oops', 500)]);

    expect(app(LicenseService::class)->validate('ANY')->status)
        ->toBe(LicenseSettings::STATUS_UNREACHABLE);
});

// F-3.25.12-002 — validateAndStore persists the outcome
it('stores the validation outcome in the license settings', function (): void {
    config(['license.endpoint' => LICENSE_ENDPOINT]);

    Http::fake([
        LICENSE_ENDPOINT => Http::response([
            'valid' => true,
            'type' => 'commercial',
            'expires_at' => '2027-12-31',
            'allowed_domains' => ['example.com'],
            'renewal_url' => 'https://license.example.test/renew/ABC',
        ]),
    ]);

    app(LicenseService::class)->validateAndStore(app(LicenseSettings::class), 'VALID-KEY');

    $settings = app(LicenseSettings::class)->refresh();

    expect($settings->license_key)->toBe('VALID-KEY')
        ->and($settings->status)->toBe(LicenseSettings::STATUS_VALID)
        ->and($settings->type)->toBe('commercial')
        ->and($settings->expires_at)->toBe('2027-12-31')
        ->and($settings->allowed_domains)->toBe(['example.com'])
        ->and($settings->renewal_url)->toBe('https://license.example.test/renew/ABC')
        ->and($settings->last_checked_at)->not->toBeNull();
});

it('keeps previous license details when the server is unreachable', function (): void {
    config(['license.endpoint' => LICENSE_ENDPOINT]);

    $settings = app(LicenseSettings::class);
    $settings->license_key = 'VALID-KEY';
    $settings->status = LicenseSettings::STATUS_VALID;
    $settings->type = 'commercial';
    $settings->expires_at = '2027-12-31';
    $settings->allowed_domains = ['example.com'];
    $settings->save();

    Http::fake(function (): void {
        throw new ConnectionException('Connection refused');
    });

    app(LicenseService::class)->validateAndStore($settings);

    $settings = app(LicenseSettings::class)->refresh();

    expect($settings->status)->toBe(LicenseSettings::STATUS_UNREACHABLE)
        ->and($settings->type)->toBe('commercial')
        ->and($settings->expires_at)->toBe('2027-12-31')
        ->and($settings->allowed_domains)->toBe(['example.com']);
});
