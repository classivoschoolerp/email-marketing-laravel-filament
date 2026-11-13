<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Filament\Customer\Resources\EmailTemplateResource\Pages\ListEmailTemplates;
use App\Jobs\Templates\BulkTemplateActionJob;
use App\Mail\TemplateTestMail;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\TemplateCategory;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

/** Creates a published system gallery template outside any tenant context. */
function wp10_gallery_template(array $attributes = []): EmailTemplate
{
    return app(CurrentCustomer::class)->runAs(
        null,
        fn (): EmailTemplate => EmailTemplate::factory()->gallery()->create($attributes),
    );
}

// F-2.5.1-001 — templates index renders (grid by default)
it('renders the templates index page', function (): void {
    EmailTemplate::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Spring sale']);

    $this->get(EmailTemplateResource::getUrl('index', panel: 'customer'))
        ->assertOk()
        ->assertSee('Spring sale');
});

// F-2.5.1-001 — grid/list toggle persisted in the session
it('toggles between grid and list layout', function (): void {
    $component = Livewire::test(ListEmailTemplates::class);

    expect($component->get('isGridLayout'))->toBeTrue();

    $component->call('toggleLayout');

    expect($component->get('isGridLayout'))->toBeFalse()
        ->and(session('templates.index.layout'))->toBe('list');

    $component->call('toggleLayout');

    expect($component->get('isGridLayout'))->toBeTrue()
        ->and(session('templates.index.layout'))->toBe('grid');
});

// F-2.5.1-002 — mine vs gallery, tenancy isolation
it('lists own templates and published gallery templates but never other tenants rows', function (): void {
    $mine = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);
    $gallery = wp10_gallery_template();
    $other = EmailTemplate::factory()->create(); // other customer's template

    Livewire::test(ListEmailTemplates::class)
        ->assertCanSeeTableRecords([$mine, $gallery])
        ->assertCanNotSeeTableRecords([$other]);
});

// F-2.5.1-002 — unpublished / group-restricted gallery rows stay hidden
it('hides unpublished system templates from customers', function (): void {
    $unpublished = app(CurrentCustomer::class)->runAs(
        null,
        fn (): EmailTemplate => EmailTemplate::factory()->system()->create(['is_gallery' => false]),
    );

    Livewire::test(ListEmailTemplates::class)
        ->assertCanNotSeeTableRecords([$unpublished]);
});

// F-2.5.1-002 — filter by category and by mine-vs-gallery
it('filters by category and ownership', function (): void {
    $category = TemplateCategory::factory()->create(['customer_id' => $this->customer->id]);
    $inCategory = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id,
        'category_id' => $category->id,
    ]);
    $noCategory = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);
    $gallery = wp10_gallery_template();

    Livewire::test(ListEmailTemplates::class)
        ->filterTable('category_id', $category->id)
        ->assertCanSeeTableRecords([$inCategory])
        ->assertCanNotSeeTableRecords([$noCategory, $gallery])
        ->resetTableFilters()
        ->filterTable('ownership', 'mine')
        ->assertCanSeeTableRecords([$inCategory, $noCategory])
        ->assertCanNotSeeTableRecords([$gallery])
        ->resetTableFilters()
        ->filterTable('ownership', 'gallery')
        ->assertCanSeeTableRecords([$gallery])
        ->assertCanNotSeeTableRecords([$inCategory, $noCategory]);
});

/* ====================================================================== *
 *  F-2.5.1-002 — "Filter by category, TAG, mine vs system gallery"
 *  (D-34 resolved in WP-27B: templates now carry a normalised `tags` list)
 * ====================================================================== */

// Create + normalise on the way in.
it('creates a template with tags and normalises them', function (): void {
    Livewire::test(EmailTemplateResource\Pages\CreateEmailTemplate::class)
        ->fillForm([
            'name' => 'Tagged welcome',
            'builder' => 'html',
            'tags' => ['Newsletter', ' newsletter ', 'BLACK  FRIDAY', '   ', 'promo'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = EmailTemplate::query()->where('name', 'Tagged welcome')->sole();

    // lower-cased, whitespace-collapsed, de-duplicated, blanks dropped,
    // first-appearance order preserved.
    expect($template->tags)->toBe(['newsletter', 'black friday', 'promo'])
        ->and($template->customer_id)->toBe($this->customer->id);

    // An all-blank list stores NULL rather than an empty array.
    $template->tags = ['', '  '];
    $template->save();

    expect($template->refresh()->tags)->toBeNull();
});

it('filters the index by tag, including gallery rows', function (): void {
    $promo = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id, 'name' => 'Promo blast', 'tags' => ['promo', 'q4'],
    ]);
    $newsletter = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id, 'name' => 'Weekly news', 'tags' => ['newsletter'],
    ]);
    $untagged = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id, 'name' => 'Plain',
    ]);
    $galleryPromo = wp10_gallery_template(['name' => 'Gallery promo', 'tags' => ['Promo']]);

    Livewire::test(ListEmailTemplates::class)
        ->filterTable('tag', 'promo')
        ->assertCanSeeTableRecords([$promo, $galleryPromo])
        ->assertCanNotSeeTableRecords([$newsletter, $untagged])
        ->resetTableFilters()
        // Filtering is case-insensitive by construction: tags are canonical.
        ->filterTable('tag', 'NEWSLETTER')
        ->assertCanSeeTableRecords([$newsletter])
        ->assertCanNotSeeTableRecords([$promo, $galleryPromo, $untagged])
        ->resetTableFilters()
        ->filterTable('tag', 'q4')
        ->assertCanSeeTableRecords([$promo])
        ->assertCanNotSeeTableRecords([$newsletter, $galleryPromo]);

    // The filter's option list is the browsable tag vocabulary, sorted.
    expect(array_keys(\App\Support\Templates\TemplateTags::options()))
        ->toBe(['newsletter', 'promo', 'q4']);
});

it('never leaks another tenants tags or templates through the tag filter', function (): void {
    $other = Customer::factory()->create();

    $foreign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): EmailTemplate => EmailTemplate::factory()->create([
            'customer_id' => $other->id, 'name' => 'Foreign secret', 'tags' => ['topsecret'],
        ]),
    );

    app(CurrentCustomer::class)->set($this->customer);

    $mine = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id, 'name' => 'Mine promo', 'tags' => ['promo'],
    ]);

    // The other tenant's tag is not even offered as an option…
    expect(array_keys(\App\Support\Templates\TemplateTags::options()))->toBe(['promo']);

    // …and forcing it into the filter matches nothing.
    Livewire::test(ListEmailTemplates::class)
        ->filterTable('tag', 'topsecret')
        ->assertCanNotSeeTableRecords([$foreign, $mine])
        ->resetTableFilters()
        ->filterTable('tag', 'promo')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});

// F-2.5.1-003 — search by name
it('searches templates by name', function (): void {
    $alpha = EmailTemplate::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Alpha welcome']);
    $beta = EmailTemplate::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Beta digest']);

    Livewire::test(ListEmailTemplates::class)
        ->searchTable('Alpha')
        ->assertCanSeeTableRecords([$alpha])
        ->assertCanNotSeeTableRecords([$beta]);
});

// F-2.5.1-004 — action set present; edit hidden on read-only gallery rows
it('offers the row actions and hides mutations on gallery rows', function (): void {
    $mine = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);
    $gallery = wp10_gallery_template();

    Livewire::test(ListEmailTemplates::class)
        ->assertTableActionExists('edit')
        ->assertTableActionExists('preview')
        ->assertTableActionExists('duplicate')
        ->assertTableActionExists('send_test')
        ->assertTableActionExists('set_default')
        ->assertTableActionExists('export_html')
        ->assertTableActionExists('export_zip')
        ->assertTableActionExists('delete')
        ->assertTableActionExists('use_template')
        ->assertTableActionVisible('edit', $mine)
        ->assertTableActionHidden('edit', $gallery)
        ->assertTableActionHidden('duplicate', $gallery)
        ->assertTableActionHidden('delete', $gallery)
        ->assertTableActionHidden('use_template', $mine)
        ->assertTableActionVisible('use_template', $gallery);
});

// F-2.5.1-004 — duplicate
it('duplicates a template', function (): void {
    $template = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Original',
    ]);

    Livewire::test(ListEmailTemplates::class)
        ->callTableAction('duplicate', $template);

    $copy = EmailTemplate::query()->where('name', 'like', 'Original %')->first();

    expect($copy)->not->toBeNull()
        ->and($copy->customer_id)->toBe($this->customer->id)
        ->and($copy->uid)->not->toBe($template->uid)
        ->and($copy->content_html)->toBe($template->content_html);
});

// F-2.5.1-004 + F-2.5.5-004 — send test is queued (never inline)
it('queues a test email per recipient', function (): void {
    Mail::fake();

    $template = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);

    Livewire::test(ListEmailTemplates::class)
        ->callTableAction('send_test', $template, data: [
            'recipients' => ['one@example.com', 'two@example.com'],
        ])
        ->assertHasNoTableActionErrors();

    Mail::assertQueued(TemplateTestMail::class, 2);
});

// F-2.5.1-004 — set as default (single default per tenant)
it('sets a template as the tenant default', function (): void {
    $first = EmailTemplate::factory()->create(['customer_id' => $this->customer->id, 'is_default' => true]);
    $second = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);

    Livewire::test(ListEmailTemplates::class)
        ->callTableAction('set_default', $second);

    expect($second->refresh()->is_default)->toBeTrue()
        ->and($first->refresh()->is_default)->toBeFalse();
});

// F-2.5.1-004 — delete (soft)
it('deletes an owned template', function (): void {
    $template = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);

    Livewire::test(ListEmailTemplates::class)
        ->callTableAction('delete', $template);

    $this->assertSoftDeleted('email_templates', ['id' => $template->id]);
});

// F-Phase-3-Differentiat-006 — template bulks are queued, never synchronous.
it('queues template bulk actions with the D-28 filtered selection', function (): void {
    Queue::fake();

    $category = TemplateCategory::factory()->create(['customer_id' => $this->customer->id]);
    $mine = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);
    $gallery = wp10_gallery_template();

    Livewire::test(ListEmailTemplates::class)
        ->callTableBulkAction('delete', [$mine, $gallery]);

    expect(EmailTemplate::withoutTenancy()->withTrashed()->find($mine->id)->trashed())->toBeFalse();

    Queue::assertPushed(
        BulkTemplateActionJob::class,
        fn (BulkTemplateActionJob $job): bool => $job->action === BulkTemplateActionJob::ACTION_DELETE
            && $job->templateIds === [$mine->id]      // gallery row never queued
            && $job->customerId === $this->customer->id,
    );

    Livewire::test(ListEmailTemplates::class)
        ->callTableBulkAction('move_to_category', [$mine, $gallery], data: ['category_id' => $category->id]);

    Queue::assertPushed(
        BulkTemplateActionJob::class,
        fn (BulkTemplateActionJob $job): bool => $job->action === BulkTemplateActionJob::ACTION_MOVE_CATEGORY
            && $job->templateIds === [$mine->id]
            && $job->categoryId === $category->id,
    );
});

it('never lets a queued template bulk touch another tenants rows', function (): void {
    $other = Customer::factory()->create();

    $foreign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): EmailTemplate => EmailTemplate::factory()->create(['customer_id' => $other->id]),
    );

    app(CurrentCustomer::class)->set($this->customer);

    (new BulkTemplateActionJob(
        BulkTemplateActionJob::ACTION_DELETE,
        [$foreign->id],
        (int) $this->customer->id,
        'op-templates-cross-tenant',
    ))->handle(app(CurrentCustomer::class));

    expect(EmailTemplate::withoutTenancy()->withTrashed()->find($foreign->id)->trashed())->toBeFalse();
});

// F-2.5.1-005 — bulk delete skips read-only gallery rows
it('bulk deletes owned templates but never gallery rows', function (): void {
    $a = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);
    $b = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);
    $gallery = wp10_gallery_template();

    Livewire::test(ListEmailTemplates::class)
        ->callTableBulkAction('delete', [$a, $b, $gallery]);

    $this->assertSoftDeleted('email_templates', ['id' => $a->id]);
    $this->assertSoftDeleted('email_templates', ['id' => $b->id]);

    expect(EmailTemplate::withoutTenancy()->find($gallery->id))->not->toBeNull();
});

// F-2.5.1-005 — bulk move to category
it('bulk moves templates to a category and skips gallery rows', function (): void {
    $category = TemplateCategory::factory()->create(['customer_id' => $this->customer->id]);
    $a = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);
    $b = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);
    $gallery = wp10_gallery_template();

    Livewire::test(ListEmailTemplates::class)
        ->callTableBulkAction('move_to_category', [$a, $b, $gallery], data: [
            'category_id' => $category->id,
        ]);

    expect($a->refresh()->category_id)->toBe($category->id)
        ->and($b->refresh()->category_id)->toBe($category->id)
        ->and(EmailTemplate::withoutTenancy()->find($gallery->id)->category_id)->toBeNull();
});

// F-2.5.1-004 + F-3.14 — "use this" copies a gallery template into the account
it('copies a gallery template into the customer account', function (): void {
    $gallery = wp10_gallery_template(['name' => 'Gallery welcome']);

    Livewire::test(ListEmailTemplates::class)
        ->callTableAction('use_template', $gallery);

    $copy = EmailTemplate::query()->where('name', 'Gallery welcome')->first();

    expect($copy)->not->toBeNull()
        ->and($copy->customer_id)->toBe($this->customer->id)
        ->and($copy->is_gallery)->toBeFalse()
        ->and($copy->uid)->not->toBe($gallery->uid);

    // The source gallery row is untouched.
    expect(EmailTemplate::withoutTenancy()->find($gallery->id)->customer_id)->toBeNull();
});

// F-2.5.1-001 / F-2.5.5-003 — thumbnail fallback chain. WP-27B: a template
// with real content gets the DERIVED SVG preview (never a screenshot — see
// TemplateThumbnails); only genuinely empty content reaches the placeholder.
it('serves the derived preview for imageless content and the placeholder for empty content', function (): void {
    $withContent = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id,
        'content_html' => '<html><body style="background:#0f172a"><h1>No images.</h1><p>{UNSUBSCRIBE_URL}</p></body></html>',
    ]);

    $url = $withContent->thumbnailUrl();

    expect($url)->toStartWith('data:image/svg+xml;base64,')
        ->and($url)->not->toContain('template-placeholder.svg');

    $svg = base64_decode(substr($url, strlen('data:image/svg+xml;base64,')), true);

    expect($svg)->toContain('<svg')
        ->toContain('No images.')     // first heading, from the markup
        ->toContain('#0f172a');       // dominant colour, from the markup

    $empty = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id,
        'content_html' => null,
    ]);

    expect($empty->thumbnailUrl())->toContain('template-placeholder.svg');
});
