<?php

declare(strict_types=1);

use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\CampaignResource\Pages\EditCampaignWithBasicBuilder;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Filament\Customer\Resources\EmailTemplateResource\Pages\EditWithBasicBuilder;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Services\Templates\Builders\Basic\BasicBlockRenderer;
use App\Services\Templates\Builders\Basic\BasicBlocks;
use App\Services\Templates\Builders\BuilderRegistry;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * §2.5.3 F-2.5.3-001 — basic / simple built-in drag & drop builder (WP-27B).
 * Distinct from GrapesJS (D-11), no bundled editor library, no vendor account.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

// ---------------------------------------------------------------- registry

it('registers the basic builder as a selectable driver alongside grapesjs', function (): void {
    $registry = app(BuilderRegistry::class);

    expect($registry->options())->toHaveKey('basic')
        ->and($registry->get('basic')->key())->toBe(TemplateBuilder::Basic)
        ->and($registry->get('basic')->isAvailable())->toBeTrue()
        // It is its OWN surface, not the GrapesJS page (D-11 stays intact).
        ->and($registry->get('basic')->label())->toBe(__('templates.builders.basic'));

    $template = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id, 'builder' => TemplateBuilder::Basic,
    ]);

    expect($registry->get('basic')->editUrl($template))
        ->toBe(EmailTemplateResource::getUrl('basic', ['record' => $template], panel: 'customer'))
        ->and($registry->get('basic')->editUrl($template))
        ->not->toBe($registry->get('grapesjs')->editUrl($template));
});

// ------------------------------------------------------- create + starter

it('creates a template with the basic builder and seeds the starter document', function (): void {
    Livewire::test(EmailTemplateResource\Pages\CreateEmailTemplate::class)
        ->fillForm(['name' => 'Simple welcome', 'builder' => TemplateBuilder::Basic->value])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = EmailTemplate::query()->where('name', 'Simple welcome')->sole();

    expect($template->builder)->toBe(TemplateBuilder::Basic);

    $page = Livewire::test(EditWithBasicBuilder::class, ['record' => $template->getKey()])
        ->assertOk();

    // Starter = greeting + divider + compliance footer, so the unsubscribe
    // check passes from the very first save (F-2.5.5-006).
    expect($page->get('blocks'))->toHaveCount(3);

    // Merely OPENING the page writes nothing — switching an existing template
    // to the basic builder and just looking at it must not destroy its HTML.
    expect($template->refresh()->content_json)->toBeNull();

    // The first edit materialises the document through the normal template
    // write path (revision + unsubscribe check + thumbnail refresh).
    $page->call('saveBlocks');

    $template->refresh();

    expect($template->content_html)->toContain('{UNSUBSCRIBE_URL}')
        ->toContain('<table role="presentation"')
        ->and($template->missing_unsubscribe_tag)->toBeFalse()
        ->and(json_decode((string) $template->content_json, true)['format'])->toBe(BasicBlocks::FORMAT)
        // 2 = the CreateEmailTemplate::afterCreate revision (starter HTML)
        // plus this one; the basic builder uses the same write path as every
        // other editor, so history behaves identically.
        ->and($template->revisions()->count())->toBe(2);

    // The chosen builder decides where "create" lands (F-2.5.3-005).
    expect(app(BuilderRegistry::class)->get('basic')->editUrl($template))
        ->toContain('/basic');
});

// ------------------------------------------------- add / reorder / delete

it('adds, reorders, duplicates and deletes blocks', function (): void {
    $template = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id, 'builder' => TemplateBuilder::Basic, 'content_json' => null,
    ]);

    $page = Livewire::test(EditWithBasicBuilder::class, ['record' => $template->getKey()]);

    // Start from a known document instead of the starter.
    $page->set('blocks', [])->call('saveBlocks');

    $page->call('addBlock', 'text')
        ->call('addBlock', 'image')
        ->call('addBlock', 'button')
        ->call('addBlock', 'divider');

    expect(array_column($page->get('blocks'), 'type'))
        ->toBe(['text', 'image', 'button', 'divider']);

    // Reorder by drag (moveBlockTo is what the HTML5 drop handler calls).
    $page->call('moveBlockTo', 3, 0);

    expect(array_column($page->get('blocks'), 'type'))
        ->toBe(['divider', 'text', 'image', 'button']);

    // …and by the keyboard-reachable arrows.
    $page->call('moveBlockDown', 0)->call('moveBlockUp', 3);

    expect(array_column($page->get('blocks'), 'type'))
        ->toBe(['text', 'divider', 'button', 'image']);

    // Out-of-range moves are no-ops, never crashes or duplicates.
    $page->call('moveBlockUp', 0)->call('moveBlockDown', 3)->call('moveBlockTo', 99, 0);

    expect($page->get('blocks'))->toHaveCount(4);

    $page->call('duplicateBlock', 0);

    $blocks = $page->get('blocks');

    expect($blocks)->toHaveCount(5)
        ->and($blocks[1]['type'])->toBe('text')
        // A duplicate is a NEW block, never a shared id.
        ->and($blocks[1]['id'])->not->toBe($blocks[0]['id']);

    $page->call('removeBlock', 1);

    expect(array_column($page->get('blocks'), 'type'))
        ->toBe(['text', 'divider', 'button', 'image']);

    // Every mutation persisted through the normal template write path.
    expect(BasicBlocks::decode($template->refresh()->content_json))->toHaveCount(4);
});

// ------------------------------------------------------- rendered output

it('renders email-safe table html', function (): void {
    $html = app(BasicBlockRenderer::class)->render([
        ['type' => 'text', 'content' => "Hello {SUBSCRIBER_FIRST_NAME}\nSecond line", 'align' => 'center'],
        ['type' => 'image', 'src' => 'https://cdn.example.com/hero.png', 'alt' => 'Hero', 'width' => 480],
        ['type' => 'button', 'label' => 'Shop now', 'href' => 'https://example.com/shop'],
        ['type' => 'divider', 'thickness' => 3, 'color' => '#ff0000'],
    ], 'Spring sale');

    expect($html)
        // Table layout only — nothing the Outlook Word engine cannot render.
        ->not->toContain('<div')
        ->not->toContain('display:flex')
        ->not->toContain('display:grid')
        ->toContain('<table role="presentation"')
        ->toContain('cellpadding="0" cellspacing="0" border="0"')
        ->toContain('style="width:600px;max-width:100%;')
        // Block content.
        ->toContain('Hello {SUBSCRIBER_FIRST_NAME}')   // merge tags survive
        ->toContain('<br>')                            // newline → <br>
        ->toContain('<img src="https://cdn.example.com/hero.png" alt="Hero" width="480"')
        ->toContain('>Shop now</a>')
        ->toContain('href="https://example.com/shop"')
        ->toContain('background-color:#ff0000');

    // Every style that CAN be inlined is inlined: no stylesheet at all here.
    expect(substr_count($html, '<style'))->toBe(0);
});

it('escapes user content and refuses unsafe link targets', function (): void {
    $html = app(BasicBlockRenderer::class)->render([
        ['type' => 'text', 'content' => '<script>alert(1)</script> & "quotes"'],
        ['type' => 'text', 'content' => 'evil [click](javascript:alert(1)) here'],
        ['type' => 'text', 'content' => 'good [unsubscribe]({UNSUBSCRIBE_URL}) here'],
        ['type' => 'image', 'src' => 'javascript:alert(1)'],
        ['type' => 'button', 'label' => 'x', 'href' => 'data:text/html,<script>alert(1)</script>'],
    ]);

    expect($html)
        ->not->toContain('<script')
        ->not->toContain('javascript:')
        ->not->toContain('data:text/html')
        ->toContain('&lt;script&gt;')
        // Unsafe link target → the words survive, the anchor does not.
        ->toContain('evil click here')
        // Merge-tag targets are legitimate and become real anchors.
        ->toContain('<a href="{UNSUBSCRIBE_URL}" target="_blank"')
        // An image with a rejected src renders the honest placeholder cell.
        ->toContain(__('templates.basic_builder.empty_image'));
});

it('normalises and caps block input from any source', function (): void {
    $blocks = BasicBlocks::normalize([
        ['type' => 'nonsense'],
        'not-an-array',
        ['type' => 'text', 'font_size' => 999, 'align' => 'justify', 'color' => 'red'],
        ['type' => 'image', 'width' => -5, 'src' => 'ftp://example.com/x.png'],
        ['type' => 'divider', 'thickness' => '4'],
    ]);

    expect($blocks)->toHaveCount(3)
        ->and($blocks[0]['font_size'])->toBe(48)      // clamped to the max
        ->and($blocks[0]['align'])->toBe('left')      // unknown → default
        ->and($blocks[0]['color'])->toBe('#111827')   // non-hex → default
        ->and($blocks[1]['width'])->toBe(40)          // clamped to the min
        ->and($blocks[1]['src'])->toBe('')            // ftp:// is not allowed
        ->and($blocks[2]['thickness'])->toBe(4)       // numeric string cast
        ->and(array_column($blocks, 'id'))->each->toBeString();

    // A GrapesJS project is not a basic document and must not be mistaken
    // for one — the page then offers the starter instead.
    expect(BasicBlocks::decode(json_encode(['pages' => [['id' => 'p1']]])))->toBe([])
        ->and(BasicBlocks::decode('not json'))->toBe([])
        ->and(BasicBlocks::decode(null))->toBe([]);
});

// -------------------------------------------------------------- campaigns

it('edits campaign content with the same basic builder', function (): void {
    $list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $list->id,
        'builder' => TemplateBuilder::Basic,
        'content_json' => null,
    ]);

    $page = Livewire::test(EditCampaignWithBasicBuilder::class, ['record' => $campaign->getKey()])
        ->assertOk();

    $page->set('blocks', [])->call('saveBlocks')
        ->call('addBlock', 'button');

    $campaign->refresh();

    expect($campaign->content_html)->toContain('<table role="presentation"')
        ->and($campaign->content_html)->toContain(__('templates.basic_builder.defaults.button_label'))
        ->and(BasicBlocks::decode($campaign->content_json))->toHaveCount(1);

    // The builder link on the campaign form points at the basic page.
    $this->get(CampaignResource::getUrl('basic', ['record' => $campaign]))->assertOk();
});

// ----------------------------------------------------------- authorization

it('denies the basic builder across tenants and on gallery rows', function (): void {
    $other = Customer::factory()->create();

    $foreign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): EmailTemplate => EmailTemplate::factory()->create([
            'customer_id' => $other->id, 'builder' => TemplateBuilder::Basic,
        ]),
    );

    $gallery = app(CurrentCustomer::class)->runAs(
        null,
        fn (): EmailTemplate => EmailTemplate::factory()->gallery()->create(['builder' => TemplateBuilder::Basic]),
    );

    app(CurrentCustomer::class)->set($this->customer);

    $this->get(EmailTemplateResource::getUrl('basic', ['record' => $foreign], panel: 'customer'))
        ->assertNotFound();

    $this->get(EmailTemplateResource::getUrl('basic', ['record' => $gallery], panel: 'customer'))
        ->assertForbidden();

    $otherList = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): EmailList => EmailList::factory()->create(['customer_id' => $other->id]),
    );

    $foreignCampaign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): Campaign => Campaign::factory()->create([
            'customer_id' => $other->id, 'email_list_id' => $otherList->id, 'builder' => TemplateBuilder::Basic,
        ]),
    );

    app(CurrentCustomer::class)->set($this->customer);

    $this->get(CampaignResource::getUrl('basic', ['record' => $foreignCampaign]))->assertNotFound();
});
