<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Services\Templates\Stock\PexelsProvider;
use App\Services\Templates\Stock\StockImageCredentials;
use App\Services\Templates\Stock\StockImageRegistry;
use App\Services\Templates\Stock\UnsplashProvider;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * WP-42 §2.5.4 F-2.5.4-010 — stock image library integration.
 *
 * Driver registry (docs/ARCHITECTURE.md rule 2) with Unsplash and Pexels adapters, keys
 * encrypted in the templates module's own operator settings store, and a
 * picked photo DOWNLOADED into the tenant library rather than hot-linked.
 * D-16: no live network — every call is Http::fake'd and asserted exactly.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    // Forget the memoised registry between configuration changes.
    app()->forgetInstance(StockImageRegistry::class);
});

function wp42_unsplash_body(): array
{
    return [
        'results' => [
            [
                'id' => 'ph-1',
                'alt_description' => 'A red door',
                'width' => 4000,
                'height' => 3000,
                'urls' => [
                    'thumb' => 'https://images.unsplash.com/photo-1?w=200',
                    'regular' => 'https://images.unsplash.com/photo-1?w=1080',
                ],
                'user' => ['name' => 'Ada Lovelace', 'links' => ['html' => 'https://unsplash.com/@ada']],
            ],
        ],
    ];
}

function wp42_pexels_body(): array
{
    return [
        'photos' => [
            [
                'id' => 4242,
                'alt' => 'A blue door',
                'width' => 3000,
                'height' => 2000,
                'photographer' => 'Grace Hopper',
                'photographer_url' => 'https://www.pexels.com/@grace',
                'src' => [
                    'tiny' => 'https://images.pexels.com/photos/4242/tiny.jpeg',
                    'large' => 'https://images.pexels.com/photos/4242/large.jpeg',
                ],
            ],
        ],
    ];
}

it('F-2.5.4-010 calls the exact Unsplash search endpoint and maps the results', function (): void {
    app(StockImageCredentials::class)->set('unsplash', 'unsplash-key');
    app()->forgetInstance(StockImageRegistry::class);

    Http::fake(['api.unsplash.com/*' => Http::response(wp42_unsplash_body(), 200)]);

    $this->getJson(route('builder-media.stock.search', ['provider' => 'unsplash', 'q' => 'door']))
        ->assertOk()
        ->assertExactJson([
            'provider' => 'unsplash',
            'photos' => [[
                'provider' => 'unsplash',
                'id' => 'ph-1',
                'thumb_url' => 'https://images.unsplash.com/photo-1?w=200',
                'full_url' => 'https://images.unsplash.com/photo-1?w=1080',
                'author' => 'Ada Lovelace',
                'author_url' => 'https://unsplash.com/@ada',
                'alt' => 'A red door',
                'width' => 4000,
                'height' => 3000,
            ]],
        ]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.unsplash.com/search/photos?query=door&page=1&per_page=24'
            && $request->method() === 'GET'
            && $request->header('Authorization') === ['Client-ID unsplash-key'];
    });

    Http::assertSentCount(1);
});

it('F-2.5.4-010 calls the exact Pexels search endpoint with a bare key header', function (): void {
    app(StockImageCredentials::class)->set('pexels', 'pexels-key');
    app()->forgetInstance(StockImageRegistry::class);

    Http::fake(['api.pexels.com/*' => Http::response(wp42_pexels_body(), 200)]);

    $this->getJson(route('builder-media.stock.search', ['provider' => 'pexels', 'q' => 'door', 'page' => 2]))
        ->assertOk()
        ->assertExactJson([
            'provider' => 'pexels',
            'photos' => [[
                'provider' => 'pexels',
                'id' => '4242',
                'thumb_url' => 'https://images.pexels.com/photos/4242/tiny.jpeg',
                'full_url' => 'https://images.pexels.com/photos/4242/large.jpeg',
                'author' => 'Grace Hopper',
                'author_url' => 'https://www.pexels.com/@grace',
                'alt' => 'A blue door',
                'width' => 3000,
                'height' => 2000,
            ]],
        ]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.pexels.com/v1/search?query=door&page=2&per_page=24'
            // Pexels takes the key BARE — no "Bearer", no "Client-ID".
            && $request->header('Authorization') === ['pexels-key'];
    });
});

it('F-2.5.4-010 downloads the picked photo into the tenant library instead of hot-linking it', function (): void {
    Storage::fake('public');

    app(StockImageCredentials::class)->set('unsplash', 'unsplash-key');
    app()->forgetInstance(StockImageRegistry::class);

    Http::fake([
        'images.unsplash.com/*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $response = $this->postJson(route('builder-media.stock.import'), [
        'provider' => 'unsplash',
        'url' => 'https://images.unsplash.com/photo-1?w=1080',
    ])->assertCreated();

    $path = (string) $response->json('path');

    // D-26 path, D-86 shape: tenant-owned, no template/campaign folder.
    expect($path)->toStartWith('customers/'.$this->customer->uid.'/library/stock-unsplash-')
        ->and($path)->toEndWith('.jpg');

    Storage::disk('public')->assertExists($path);
    expect(Storage::disk('public')->get($path))->toBe('PNGBYTES');
});

it('F-2.5.4-010 refuses to fetch a url the library does not serve (SSRF fence)', function (): void {
    Storage::fake('public');
    Http::fake();

    app(StockImageCredentials::class)->set('unsplash', 'unsplash-key');
    app()->forgetInstance(StockImageRegistry::class);

    $this->postJson(route('builder-media.stock.import'), [
        'provider' => 'unsplash',
        'url' => 'https://evil.example.com/payload.png',
    ])->assertUnprocessable()->assertJsonValidationErrors('url');

    // http, even on the right host, is refused too.
    $this->postJson(route('builder-media.stock.import'), [
        'provider' => 'unsplash',
        'url' => 'http://images.unsplash.com/photo-1',
    ])->assertUnprocessable()->assertJsonValidationErrors('url');

    // Nothing was ever fetched.
    Http::assertNothingSent();
});

it('F-2.5.4-010 makes the picker unavailable when no api key is configured', function (): void {
    Http::fake();

    $registry = app(StockImageRegistry::class);

    expect($registry->configuredKeys())->toBe([])
        ->and($registry->options())->toBe([])
        ->and($registry->get('unsplash'))->toBeNull()
        // The adapters exist, they are simply not usable.
        ->and(array_keys($registry->all()))->toBe(['unsplash', 'pexels']);

    // The endpoints refuse every provider value…
    $this->getJson(route('builder-media.stock.search', ['provider' => 'unsplash', 'q' => 'door']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('provider');

    $this->postJson(route('builder-media.stock.import'), [
        'provider' => 'unsplash',
        'url' => 'https://images.unsplash.com/photo-1',
    ])->assertUnprocessable()->assertJsonValidationErrors('provider');

    Http::assertNothingSent();

    // …and the builder page ships an empty provider list, so no button renders.
    $template = EmailTemplate::factory()->grapesjs()->create(['customer_id' => $this->customer->id]);

    $this->get(EmailTemplateResource::getUrl('builder', ['record' => $template], panel: 'customer'))
        ->assertOk()
        ->assertSee('"providers":[]', false);
});

it('F-2.5.4-010 stores the api key encrypted and falls back to config', function (): void {
    $credentials = app(StockImageCredentials::class);

    $credentials->set('unsplash', 'plain-secret');

    $stored = (string) \App\Models\TemplateSetting::query()
        ->where('key', 'stock.unsplash.api_key')
        ->value('value');

    expect($stored)->not->toBe('plain-secret')
        ->and($stored)->not->toContain('plain-secret')
        ->and($credentials->get('unsplash'))->toBe('plain-secret')
        ->and($credentials->has('unsplash'))->toBeTrue();

    // Clearing the row falls through to the env/config value.
    $credentials->set('unsplash', null);
    config(['emp-templates.stock.unsplash.api_key' => 'from-config']);

    expect($credentials->get('unsplash'))->toBe('from-config')
        ->and($credentials->get('pexels'))->toBeNull();
});

it('F-2.5.4-010 survives a library that errors or answers with junk', function (): void {
    app(StockImageCredentials::class)->set('unsplash', 'unsplash-key');
    app(StockImageCredentials::class)->set('pexels', 'pexels-key');
    app()->forgetInstance(StockImageRegistry::class);

    Http::fake([
        'api.unsplash.com/*' => Http::response('nope', 500),
        'api.pexels.com/*' => Http::response(['photos' => ['not-an-array', ['id' => 1]]], 200),
    ]);

    expect(app(UnsplashProvider::class)->search('door'))->toBe([])
        // A photo with no usable urls is skipped, not rendered broken.
        ->and(app(PexelsProvider::class)->search('door'))->toBe([]);

    $this->getJson(route('builder-media.stock.search', ['provider' => 'unsplash', 'q' => 'door']))
        ->assertOk()
        ->assertJsonPath('photos', []);
});

it('F-2.5.4-010 requires an authenticated customer', function (): void {
    auth('customer')->logout();
    app(CurrentCustomer::class)->set(null);

    $this->getJson(route('builder-media.stock.search', ['provider' => 'unsplash', 'q' => 'x']))
        ->assertUnauthorized();

    $this->postJson(route('builder-media.stock.import'), ['provider' => 'unsplash', 'url' => 'https://x.test/a.png'])
        ->assertUnauthorized();
});
