<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\LandingPageResource\Pages\BuildLandingPage;
use App\Filament\Customer\Resources\LandingPageResource\Pages\LandingTemplateGallery;
use App\Models\Customer;
use App\Models\LandingPage;
use App\Models\LandingPageRevision;
use App\Models\LandingPageVariant;
use App\Services\Landing\LandingBlocks;
use App\Services\Landing\LandingPageRevisions;
use App\Services\Landing\LandingTemplates;
use App\Support\Tenancy\CurrentCustomer;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;

/*
 * §2.6 builder, gallery and revisions.
 *
 * F-2.6-002 drag & drop builder (the shared GrapesJS editor in landing mode)
 * F-2.6-003 template gallery
 * F-2.6-005 revisions with restore
 * F-2.6-013 mobile responsive preview
 * plus the cross-tenant fence on the autosave endpoint.
 */

beforeEach(function (): void {
    $this->customer = Fx::actingAsCustomer();
    $this->page = LandingPage::factory()->create([
        'customer_id' => $this->customer->id,
        'content_html' => '<h1>v1</h1>',
    ]);
});

it('renders the builder page in landing mode with the landing block palette (F-2.6-002)', function (): void {
    Livewire::test(BuildLandingPage::class, ['record' => $this->page->getKey()])
        ->assertOk()
        // The shared editor, told it is a landing page (@json hex-escapes quotes).
        ->assertSee('"mode":"landing"', false)
        // The four §2.6 headline blocks are in the palette…
        ->assertSee(__('landing.blocks.countdown'))
        ->assertSee(__('landing.blocks.testimonials'))
        ->assertSee(__('landing.blocks.pricing'))
        ->assertSee(__('landing.blocks.form'))
        // …and the desktop/tablet/mobile preview note (F-2.6-013).
        ->assertSee(__('landing.builder_page.device_hint'));

    $blockIds = array_column(LandingBlocks::all(), 'id');

    expect($blockIds)->toContain('section', 'hero', 'columns', 'form', 'countdown', 'testimonials', 'pricing');
});

it('autosaves the page content and records a revision (F-2.6-002, F-2.6-005)', function (): void {
    $response = $this->postJson(route('landing.builder.save', ['uid' => $this->page->uid]), [
        'content_html' => '<h1>v2</h1>',
        'content_json' => json_encode(['pages' => []]),
    ]);

    $response->assertOk()->assertJson(['saved' => true]);

    expect($this->page->refresh()->content_html)->toBe('<h1>v2</h1>')
        ->and(LandingPageRevision::query()->where('landing_page_id', $this->page->id)->count())->toBe(1);
});

it('keeps the hide-on-mobile media query with the markup that uses it (F-2.6-013)', function (): void {
    $this->postJson(route('landing.builder.save', ['uid' => $this->page->uid]), [
        'content_html' => '<div class="hide-mobile">phone-hostile</div>',
    ])->assertOk();

    // A media query cannot be inlined, so the saved document must carry it.
    expect($this->page->refresh()->content_html)->toContain('max-width:600px')
        ->and($this->page->content_html)->toContain('.hide-mobile');
});

it('saves a variant document without touching the page document (F-2.6-004)', function (): void {
    $variant = LandingPageVariant::factory()->create([
        'landing_page_id' => $this->page->id,
        'customer_id' => $this->customer->id,
        'content_html' => '<h1>B1</h1>',
    ]);

    $this->postJson(route('landing.builder.save', ['uid' => $this->page->uid]), [
        'content_html' => '<h1>B2</h1>',
        'variant_uid' => $variant->uid,
    ])->assertOk();

    expect($variant->refresh()->content_html)->toBe('<h1>B2</h1>')
        ->and($this->page->refresh()->content_html)->toBe('<h1>v1</h1>');

    // The revision belongs to the VARIANT's history, not the page's.
    $revision = LandingPageRevision::query()->where('landing_page_id', $this->page->id)->firstOrFail();

    expect((int) $revision->landing_page_variant_id)->toBe((int) $variant->id);

    // A variant uid that is not one of this page's arms is a 404, never a
    // silent write to the page document.
    $otherPage = LandingPage::factory()->create(['customer_id' => $this->customer->id]);
    $other = LandingPageVariant::factory()->create([
        'landing_page_id' => $otherPage->id,
        'customer_id' => $this->customer->id,
    ]);

    $this->postJson(route('landing.builder.save', ['uid' => $this->page->uid]), [
        'content_html' => '<h1>nope</h1>',
        'variant_uid' => $other->uid,
    ])->assertNotFound();
});

it('prunes revisions to the configured keep count and restores one (F-2.6-005)', function (): void {
    config()->set('emp-templates.revisions_keep', 3);

    $revisions = app(LandingPageRevisions::class);

    foreach (range(1, 6) as $i) {
        $this->page->forceFill(['content_html' => '<h1>rev'.$i.'</h1>'])->save();
        $revisions->record($this->page);
    }

    expect(LandingPageRevision::query()->where('landing_page_id', $this->page->id)->count())->toBe(3);

    $target = LandingPageRevision::query()
        ->where('landing_page_id', $this->page->id)
        ->orderBy('id')
        ->firstOrFail();

    $expected = $target->content_html;

    $revisions->restore($this->page, $target);

    expect($this->page->refresh()->content_html)->toBe($expected)
        // The pre-restore content was recorded first, so the restore is undoable.
        ->and(LandingPageRevision::query()->where('landing_page_id', $this->page->id)
            ->orderByDesc('id')->first()->content_html)->toBe('<h1>rev6</h1>');
});

it('restores a revision from the builder page UI (F-2.6-005)', function (): void {
    app(LandingPageRevisions::class)->record($this->page);

    $this->page->forceFill(['content_html' => '<h1>oops</h1>'])->save();

    $revision = LandingPageRevision::query()->where('landing_page_id', $this->page->id)->firstOrFail();

    Livewire::test(BuildLandingPage::class, ['record' => $this->page->getKey()])
        ->call('restoreRevision', $revision->id);

    expect($this->page->refresh()->content_html)->toBe('<h1>v1</h1>');
});

it('applies a starter design from the gallery (F-2.6-003)', function (): void {
    Livewire::test(LandingTemplateGallery::class, ['record' => $this->page->getKey()])
        ->assertOk()
        ->assertSee(__('landing.gallery.designs.webinar.name'))
        ->call('applyDesign', 'webinar');

    $this->page->refresh();

    expect($this->page->template_key)->toBe('webinar')
        ->and($this->page->content_html)->toContain(LandingBlocks::COUNTDOWN_ATTRIBUTE)
        ->and($this->page->content_html)->toContain(LandingBlocks::FORM_PLACEHOLDER_ATTRIBUTE)
        // The previous document is recoverable.
        ->and(LandingPageRevision::query()->where('landing_page_id', $this->page->id)->count())->toBe(1);

    // Every advertised design produces markup.
    foreach (LandingTemplates::KEYS as $key) {
        expect(trim(LandingTemplates::html($key)))->not->toBe('');
    }

    // An unknown key is refused rather than silently blanking the page.
    Livewire::test(LandingTemplateGallery::class, ['record' => $this->page->getKey()])
        ->call('applyDesign', 'not-a-design')
        ->assertStatus(404);

    expect($this->page->refresh()->template_key)->toBe('webinar');
});

it('refuses the autosave endpoint for another tenant\'s page', function (): void {
    $other = Customer::factory()->create();

    $foreign = app(CurrentCustomer::class)->runAs($other, fn (): LandingPage => LandingPage::factory()
        ->create(['customer_id' => $other->id, 'content_html' => '<h1>theirs</h1>']));

    $this->postJson(route('landing.builder.save', ['uid' => $foreign->uid]), [
        'content_html' => '<h1>hijacked</h1>',
    ])->assertForbidden();

    expect($foreign->refresh()->content_html)->toBe('<h1>theirs</h1>');

    // An unknown uid is a 404, never a 403 that confirms existence.
    $this->postJson(route('landing.builder.save', ['uid' => 'no-such-uid']), [
        'content_html' => '<h1>x</h1>',
    ])->assertNotFound();
});
