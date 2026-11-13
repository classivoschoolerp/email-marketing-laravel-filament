<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * WP-42 §2.5.4 F-2.5.4-009 — image editor (crop, resize, filters, text
 * overlay).
 *
 * SCOPE OF THIS FILE, stated plainly: the editing itself is CLIENT-SIDE, on a
 * <canvas>, in resources/js/template-builder.js — no npm package was added and
 * no server-side image library is involved. What the server owns, and what is
 * asserted here, is the endpoint that stores the edited result as a NEW
 * tenant-owned asset (the original is never overwritten) and the fence that
 * keeps one tenant out of another's library.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

it('F-2.5.4-009 stores an edited image as a new asset in the tenant library', function (): void {
    Storage::fake('public');

    $response = $this->post(route('builder-media.images.store'), [
        'file' => UploadedFile::fake()->image('edited.png', 400, 300),
        'name' => 'Hero cropped',
    ]);

    $response->assertCreated()->assertJsonStructure(['path', 'url']);

    $path = (string) $response->json('path');

    // D-26 public disk under customers/{uid}/, D-86 shape: the library is
    // tenant-owned and carries no template or campaign folder, so the same
    // edited image is reusable from BOTH builders.
    expect($path)->toStartWith('customers/'.$this->customer->uid.'/library/hero-cropped-')
        ->and($path)->toEndWith('.png');

    Storage::disk('public')->assertExists($path);
});

it('F-2.5.4-009 rejects anything that is not a raster image', function (): void {
    Storage::fake('public');

    $this->post(
        route('builder-media.images.store'),
        ['file' => UploadedFile::fake()->create('payload.php', 8, 'text/x-php')],
        ['Accept' => 'application/json'],
    )->assertUnprocessable()->assertJsonValidationErrors('file');

    // The canvas rasterises, so an "edited" SVG is a contradiction — and an
    // SVG upload is also an XSS vector on a public disk.
    $this->post(
        route('builder-media.images.store'),
        ['file' => UploadedFile::fake()->create('logo.svg', 8, 'image/svg+xml')],
        ['Accept' => 'application/json'],
    )->assertUnprocessable()->assertJsonValidationErrors('file');

    $this->postJson(route('builder-media.images.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

it('F-2.5.4-009 fences edited images per tenant', function (): void {
    Storage::fake('public');

    $other = Customer::factory()->create();

    $mine = (string) $this->post(route('builder-media.images.store'), [
        'file' => UploadedFile::fake()->image('mine.png', 40, 40),
    ])->assertCreated()->json('path');

    // The second tenant's upload can only ever land in the second tenant's
    // own directory — the owner comes from the session, never the request.
    $this->actingAs($other, 'customer');
    app(CurrentCustomer::class)->set($other);

    $theirs = (string) $this->post(route('builder-media.images.store'), [
        'file' => UploadedFile::fake()->image('theirs.png', 40, 40),
    ])->assertCreated()->json('path');

    expect($mine)->toStartWith('customers/'.$this->customer->uid.'/library/')
        ->and($theirs)->toStartWith('customers/'.$other->uid.'/library/')
        ->and($theirs)->not->toContain($this->customer->uid)
        ->and($mine)->not->toContain($other->uid);

    Storage::disk('public')->assertExists($mine);
    Storage::disk('public')->assertExists($theirs);
});

it('F-2.5.4-009 requires an authenticated customer', function (): void {
    auth('customer')->logout();
    app(CurrentCustomer::class)->set(null);

    $this->postJson(route('builder-media.images.store'))->assertUnauthorized();
});

it('F-2.5.4-009 ships the image editor endpoint to the builder page', function (): void {
    $template = EmailTemplate::factory()->grapesjs()->create(['customer_id' => $this->customer->id]);

    $this->get(EmailTemplateResource::getUrl('builder', ['record' => $template], panel: 'customer'))
        ->assertOk()
        ->assertSee('imageEditor', false)
        ->assertSee(__('builder.image_editor.label'), false)
        ->assertSee(__('builder.image_editor.filters.grayscale'), false);
});
