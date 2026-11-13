<?php

declare(strict_types=1);

use App\Enums\VerificationStatus;
use App\Services\Verification\ProviderRegistry;
use App\Services\Verification\Providers\BouncerProvider;
use App\Services\Verification\Providers\BuiltInVerifier;
use App\Services\Verification\Providers\DebounceProvider;
use App\Services\Verification\Providers\EmailableProvider;
use App\Services\Verification\Providers\KickboxProvider;
use App\Services\Verification\Providers\LocalmailProvider;
use App\Services\Verification\Providers\NeverBounceProvider;
use App\Services\Verification\Providers\VerifyreProvider;
use App\Services\Verification\Providers\ZeroBounceProvider;
use App\Services\Verification\VerificationProvider;
use App\Services\Verification\VerificationResult;
use Illuminate\Support\Facades\Http;

/*
 * WP-16 §2.9.12 F-2.9.12-001 (D-12) — the eight external adapters against
 * their documented HTTP APIs: auth placement, payload and the mapping of
 * each vendor's distinct status vocabulary onto D/U/R/Unknown. All faked —
 * live verification needs keys (D-12).
 */

it('registers all eight vendors plus the built-in engine', function (): void {
    $registry = app(ProviderRegistry::class);

    expect($registry->keys())->toContain(
        'built-in', 'emailable', 'kickbox', 'zerobounce', 'debounce',
        'neverbounce', 'bouncer', 'localmail', 'verifyre',
    );

    expect($registry->make('kickbox', 'k')->name())->toBe('Kickbox')
        ->and($registry->options())->toHaveKey('emailable');
});

it('lets plugins register new vendors without core changes (F-2.9.2-021 pattern)', function (): void {
    $registry = app(ProviderRegistry::class);

    $registry->register('acme-verify', fn (?string $apiKey): VerificationProvider => new class implements VerificationProvider
    {
        public function key(): string
        {
            return 'acme-verify';
        }

        public function name(): string
        {
            return 'Acme';
        }

        public function requiresApiKey(): bool
        {
            return false;
        }

        public function verify(string $email): VerificationResult
        {
            return VerificationResult::deliverable('plugin');
        }
    });

    expect($registry->has('acme-verify'))->toBeTrue()
        ->and($registry->make('acme-verify')->verify('x@y.example')->status)->toBe(VerificationStatus::Deliverable);
});

it('rejects unknown provider keys and non-provider classes', function (): void {
    $registry = app(ProviderRegistry::class);

    expect(fn () => $registry->make('nope'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('bad', \stdClass::class))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Emailable — GET api.emailable.com/v1/verify?email&api_key, `state`
// ---------------------------------------------------------------------------
it('maps the Emailable vocabulary and sends the api_key', function (string $state, VerificationStatus $expected): void {
    Http::fake(['api.emailable.com/*' => Http::response(['state' => $state, 'reason' => 'r1'])]);

    $result = (new EmailableProvider('key-e'))->verify('User@Example.com');

    expect($result->status)->toBe($expected);

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), EmailableProvider::ENDPOINT)
        && $request['email'] === 'user@example.com'
        && $request['api_key'] === 'key-e');
})->with([
    ['deliverable', VerificationStatus::Deliverable],
    ['undeliverable', VerificationStatus::Undeliverable],
    ['risky', VerificationStatus::Risky],
    ['unknown', VerificationStatus::Unknown],
]);

// ---------------------------------------------------------------------------
// Kickbox — GET api.kickbox.com/v2/verify?email&apikey, `result`
// ---------------------------------------------------------------------------
it('maps the Kickbox vocabulary and sends the apikey', function (string $vendor, VerificationStatus $expected): void {
    Http::fake(['api.kickbox.com/*' => Http::response(['result' => $vendor, 'reason' => 'accepted_email'])]);

    $result = (new KickboxProvider('key-k'))->verify('a@b.example');

    expect($result->status)->toBe($expected)
        ->and($result->raw)->toHaveKey('result');

    Http::assertSent(fn ($request): bool => $request['apikey'] === 'key-k' && $request['email'] === 'a@b.example');
})->with([
    ['deliverable', VerificationStatus::Deliverable],
    ['undeliverable', VerificationStatus::Undeliverable],
    ['risky', VerificationStatus::Risky],
    ['unknown', VerificationStatus::Unknown],
]);

// ---------------------------------------------------------------------------
// ZeroBounce — GET api.zerobounce.net/v2/validate, `status`/`sub_status`
// ---------------------------------------------------------------------------
it('maps the ZeroBounce vocabulary', function (string $vendor, VerificationStatus $expected): void {
    Http::fake(['api.zerobounce.net/*' => Http::response(['status' => $vendor, 'sub_status' => 'sub'])]);

    $result = (new ZeroBounceProvider('key-z'))->verify('a@b.example');

    expect($result->status)->toBe($expected)
        ->and($result->reason)->toBe('sub');

    Http::assertSent(fn ($request): bool => $request['api_key'] === 'key-z');
})->with([
    ['valid', VerificationStatus::Deliverable],
    ['invalid', VerificationStatus::Undeliverable],
    ['catch-all', VerificationStatus::Risky],
    ['spamtrap', VerificationStatus::Risky],
    ['abuse', VerificationStatus::Risky],
    ['do_not_mail', VerificationStatus::Risky],
    ['unknown', VerificationStatus::Unknown],
]);

// ---------------------------------------------------------------------------
// DeBounce — GET api.debounce.io/v1/?api&email, nested `debounce.result`
// ---------------------------------------------------------------------------
it('maps the DeBounce vocabulary from the nested payload', function (string $vendor, VerificationStatus $expected): void {
    Http::fake(['api.debounce.io/*' => Http::response(['debounce' => ['result' => $vendor, 'code' => '5']])]);

    $result = (new DebounceProvider('key-d'))->verify('a@b.example');

    expect($result->status)->toBe($expected);

    Http::assertSent(fn ($request): bool => $request['api'] === 'key-d' && $request['email'] === 'a@b.example');
})->with([
    ['Safe to Send', VerificationStatus::Deliverable],
    ['Invalid', VerificationStatus::Undeliverable],
    ['Risky', VerificationStatus::Risky],
    ['Unknown', VerificationStatus::Unknown],
]);

// ---------------------------------------------------------------------------
// NeverBounce — GET api.neverbounce.com/v4/single/check?key&email, `result`
// ---------------------------------------------------------------------------
it('maps the NeverBounce vocabulary inside the success envelope', function (string $vendor, VerificationStatus $expected): void {
    Http::fake(['api.neverbounce.com/*' => Http::response(['status' => 'success', 'result' => $vendor])]);

    $result = (new NeverBounceProvider('key-n'))->verify('a@b.example');

    expect($result->status)->toBe($expected);

    Http::assertSent(fn ($request): bool => $request['key'] === 'key-n');
})->with([
    ['valid', VerificationStatus::Deliverable],
    ['invalid', VerificationStatus::Undeliverable],
    ['disposable', VerificationStatus::Risky],
    ['catchall', VerificationStatus::Risky],
    ['unknown', VerificationStatus::Unknown],
]);

it('treats a NeverBounce failure envelope as unknown', function (): void {
    Http::fake(['api.neverbounce.com/*' => Http::response(['status' => 'auth_failure', 'message' => 'bad key'])]);

    $result = (new NeverBounceProvider('key-n'))->verify('a@b.example');

    expect($result->status)->toBe(VerificationStatus::Unknown)
        ->and($result->reason)->toBe('bad key');
});

// ---------------------------------------------------------------------------
// Bouncer — GET api.usebouncer.com/v1.1/email/verify, x-api-key header
// ---------------------------------------------------------------------------
it('maps the Bouncer vocabulary and authenticates via the x-api-key header', function (string $vendor, VerificationStatus $expected): void {
    Http::fake(['api.usebouncer.com/*' => Http::response(['status' => $vendor, 'reason' => 'r'])]);

    $result = (new BouncerProvider('key-b'))->verify('a@b.example');

    expect($result->status)->toBe($expected);

    Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'key-b'));
})->with([
    ['deliverable', VerificationStatus::Deliverable],
    ['undeliverable', VerificationStatus::Undeliverable],
    ['risky', VerificationStatus::Risky],
    ['unknown', VerificationStatus::Unknown],
]);

// ---------------------------------------------------------------------------
// Localmail — GET {base}/verify?email&api_key, `result` (base configurable)
// ---------------------------------------------------------------------------
it('maps the Localmail vocabulary and honors the configurable base url', function (): void {
    config(['emp-verification.providers.localmail.base_url' => 'https://evs.internal.example/v1']);
    Http::fake(['evs.internal.example/*' => Http::response(['result' => 'undeliverable', 'reason' => 'mailbox_full'])]);

    $result = (new LocalmailProvider('key-l'))->verify('a@b.example');

    expect($result->status)->toBe(VerificationStatus::Undeliverable)
        ->and($result->reason)->toBe('mailbox_full');

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://evs.internal.example/v1/verify')
        && $request['api_key'] === 'key-l');
});

// ---------------------------------------------------------------------------
// Verifyre — POST {base}/verify {"email"}, Bearer token, `status`
// ---------------------------------------------------------------------------
it('maps the Verifyre vocabulary posting JSON with a bearer token', function (): void {
    Http::fake(['api.verifyre.com/*' => Http::response(['status' => 'risky', 'reason' => 'accept_all'])]);

    $result = (new VerifyreProvider('key-v'))->verify('a@b.example');

    expect($result->status)->toBe(VerificationStatus::Risky);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer key-v')
        && $request['email'] === 'a@b.example');
});

// ---------------------------------------------------------------------------
// Failure semantics shared by every HTTP adapter
// ---------------------------------------------------------------------------
it('maps vendor failures to unknown instead of throwing', function (): void {
    Http::fake(['api.kickbox.com/*' => Http::response(['error' => 'boom'], 500)]);

    $result = (new KickboxProvider('key'))->verify('a@b.example');

    expect($result->status)->toBe(VerificationStatus::Unknown)
        ->and($result->reason)->toBe('provider_http_500');
});

it('short-circuits to unknown when no API key is configured', function (): void {
    Http::fake();

    $result = (new EmailableProvider(null))->verify('a@b.example');

    expect($result->status)->toBe(VerificationStatus::Unknown)
        ->and($result->reason)->toBe('missing_api_key');

    Http::assertNothingSent();
});

it('exposes requiresApiKey correctly for built-in vs external', function (): void {
    expect((new BuiltInVerifier)->requiresApiKey())->toBeFalse()
        ->and((new KickboxProvider('k'))->requiresApiKey())->toBeTrue();
});
