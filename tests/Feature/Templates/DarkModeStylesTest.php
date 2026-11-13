<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Services\Content\DarkModePreview;
use App\Support\Templates\DarkModeStyles;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;

/*
 * WP-42 §2.5.4 F-2.5.4-013 — dark mode styles.
 *
 * App\Services\Content\DarkModePreview already SIMULATED dark mode in the
 * panel (F-2.3.3-027). The half proven here is the one that was missing: the
 * `prefers-color-scheme: dark` rules are EMITTED into the HTML that is stored
 * and sent, driven by per-block overrides the builder writes as classes.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->template = EmailTemplate::factory()->grapesjs()->create([
        'customer_id' => $this->customer->id,
    ]);
});

/** Markup carrying one dark background override and one dark text override. */
function wp42_dark_html(): string
{
    return '<html><head><title>t</title></head><body>'
        .'<table class="card dm-bg-111827"><tr><td class="dm-fg-f9fafb">Hello</td></tr></table>'
        .'<a href="{UNSUBSCRIBE_URL}">bye</a></body></html>';
}

it('F-2.5.4-013 emits the exact prefers-color-scheme rules into the saved template html', function (): void {
    $this->postJson(route('templates.builder.save', ['uid' => $this->template->uid]), [
        'content_html' => wp42_dark_html(),
    ])->assertOk();

    $saved = (string) $this->template->refresh()->content_html;

    // The full emitted stylesheet, byte for byte.
    $expectedCss = ':root{color-scheme:light dark;supported-color-schemes:light dark;}'
        .'@media (prefers-color-scheme:dark){'
        .'.dm-bg-111827{background-color:#111827!important;}'
        .'.dm-fg-f9fafb{color:#f9fafb!important;}'
        .'}'
        .'[data-ogsb] .dm-bg-111827{background-color:#111827!important;}'
        .'[data-ogsc] .dm-fg-f9fafb{color:#f9fafb!important;}';

    expect($saved)->toContain('<style type="text/css" data-dark-mode>'.$expectedCss.'</style>')
        // The two meta tags a dark-capable client reads to learn the message
        // opts in rather than being force-inverted.
        ->toContain('<meta name="color-scheme" content="light dark">')
        ->toContain('<meta name="supported-color-schemes" content="light dark">')
        // Injected in the head, which is where it has to be — the rule can
        // never be inlined onto the element.
        ->and(str_contains($saved, '</style></head>'))->toBeTrue()
        // The author's own markup is untouched.
        ->and($saved)->toContain('<table class="card dm-bg-111827">');
});

it('F-2.5.4-013 leaves markup without dark overrides untouched and never stacks duplicates', function (): void {
    $plain = '<html><body><p>Hi</p><a href="{UNSUBSCRIBE_URL}">bye</a></body></html>';

    $this->postJson(route('templates.builder.save', ['uid' => $this->template->uid]), [
        'content_html' => $plain,
    ])->assertOk();

    expect($this->template->refresh()->content_html)->toBe($plain);

    // …and re-saving already-emitted markup is a no-op.
    $this->postJson(route('templates.builder.save', ['uid' => $this->template->uid]), [
        'content_html' => wp42_dark_html(),
    ])->assertOk();

    $once = (string) $this->template->refresh()->content_html;

    $this->postJson(route('templates.builder.save', ['uid' => $this->template->uid]), [
        'content_html' => $once,
    ])->assertOk();

    $twice = (string) $this->template->refresh()->content_html;

    expect($twice)->toBe($once)
        ->and(substr_count($twice, 'data-dark-mode'))->toBe(1)
        ->and(substr_count($twice, '<meta name="color-scheme"'))->toBe(1);
});

it('F-2.5.4-013 emits the same dark rules from the campaign builder (shared editor)', function (): void {
    $list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $list->id,
    ]);

    $this->postJson(route('campaigns.builder.save', ['uid' => $campaign->uid]), [
        'content_html' => wp42_dark_html(),
    ])->assertOk();

    expect((string) $campaign->refresh()->content_html)
        ->toContain('@media (prefers-color-scheme:dark){.dm-bg-111827{background-color:#111827!important;}')
        ->toContain('.dm-fg-f9fafb{color:#f9fafb!important;}}');
});

it('F-2.5.4-013 normalises colours, de-duplicates and sorts the emitted rules', function (): void {
    // Same colour used on three blocks, plus a second colour out of order.
    $html = '<div class="dm-bg-ff0000"><span class="dm-bg-ff0000">a</span>'
        .'<span class="dm-bg-00ff00">b</span><span class="dm-bg-FF0000">c</span></div>';

    $out = (string) DarkModeStyles::apply($html);

    expect($out)->toStartWith(
        '<style type="text/css" data-dark-mode>'
        .':root{color-scheme:light dark;supported-color-schemes:light dark;}'
        .'@media (prefers-color-scheme:dark){'
        .'.dm-bg-00ff00{background-color:#00ff00!important;}'
        .'.dm-bg-ff0000{background-color:#ff0000!important;}'
        .'}'
    );

    // Class helpers accept #RRGGBB, RRGGBB, #RGB and mixed case alike.
    expect(DarkModeStyles::bgClass('#111827'))->toBe('dm-bg-111827')
        ->and(DarkModeStyles::bgClass('#FFF'))->toBe('dm-bg-ffffff')
        ->and(DarkModeStyles::fgClass('F9FAFB'))->toBe('dm-fg-f9fafb')
        ->and(DarkModeStyles::used(null))->toBe(['bg' => [], 'fg' => []]);
});

it('F-2.5.4-013 injects the dark rules into head-less markup and bare fragments too', function (): void {
    expect(DarkModeStyles::apply('<div class="dm-fg-ffffff">x</div>'))
        ->toStartWith('<style type="text/css" data-dark-mode>')
        ->and(DarkModeStyles::apply('<body><p class="dm-bg-000000">x</p></body>'))
        ->toContain('<body><style type="text/css" data-dark-mode>')
        // No dark class anywhere = nothing emitted, ever.
        ->and(DarkModeStyles::apply('<p>plain</p>'))->toBe('<p>plain</p>')
        ->and(DarkModeStyles::apply(null))->toBeNull();
});

it('F-2.5.4-013 renders the emitted rules unconditionally in the author dark preview', function (): void {
    $html = (string) DarkModeStyles::apply(wp42_dark_html());

    $dark = app(DarkModePreview::class)->simulate($html, DarkModePreview::MODE_AUTHOR);

    // The media-query wrapper is stripped, the author's own declarations stay —
    // which is the only way a preview iframe can show them.
    expect($dark)->not->toContain('prefers-color-scheme')
        ->and($dark)->toContain('.dm-bg-111827{background-color:#111827!important;}')
        ->and($dark)->toContain('.dm-fg-f9fafb{color:#f9fafb!important;}')
        ->and($dark)->toContain('data-dark-preview');
});

it('F-2.5.4-013 ships the dark controls in the builder page config', function (): void {
    $this->get(EmailTemplateResource::getUrl('builder', ['record' => $this->template], panel: 'customer'))
        ->assertOk()
        ->assertSee('"dark"', false)
        ->assertSee(__('templates.builder_page.dark.preview'), false)
        ->assertSee(__('templates.builder_page.dark.background'), false);

    expect(__('templates.builder_page.dark'))
        ->toHaveKeys(['background', 'background_hint', 'text', 'text_hint', 'clear', 'preview', 'preview_hint', 'nothing_selected']);
});
