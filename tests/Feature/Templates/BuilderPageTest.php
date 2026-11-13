<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Filament\Customer\Resources\EmailTemplateResource\Pages\BuildEmailTemplate;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Services\Templates\MergeTags;
use App\Services\Templates\TemplateRevisions;
use App\Settings\CampaignSettings;
use App\Support\Templates\MobileVisibility;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->template = EmailTemplate::factory()->grapesjs()->create([
        'customer_id' => $this->customer->id,
    ]);
});

function wp10_valid_html(): string
{
    return '<html><body><p>Hi {SUBSCRIBER_NAME}</p><a href="{UNSUBSCRIBE_URL}">bye</a></body></html>';
}

// §2.5.4 — the builder page renders with its config payload
it('renders the builder page with save endpoint and merge tags', function (): void {
    // NB: @json escapes slashes, so URL assertions use fragments.
    $this->get(EmailTemplateResource::getUrl('builder', ['record' => $this->template], panel: 'customer'))
        ->assertOk()
        ->assertSee('template-builder-config', false)
        ->assertSee('UNSUBSCRIBE_URL', false)
        ->assertSee($this->template->uid, false)
        ->assertSee('saveUrl', false);
});

// F-2.5.4-002 — merge tags come from the seeded D-22 list
it('exposes the known template tags as merge tags', function (): void {
    expect(app(MergeTags::class)->tags())->toBe(CampaignSettings::KNOWN_TEMPLATE_TAGS);
});

// §2.5.4 — builder page is denied for gallery rows and unknown templates
it('denies the builder page on gallery templates', function (): void {
    $gallery = app(CurrentCustomer::class)->runAs(
        null,
        fn (): EmailTemplate => EmailTemplate::factory()->gallery()->create(),
    );

    $this->get(EmailTemplateResource::getUrl('builder', ['record' => $gallery], panel: 'customer'))
        ->assertForbidden();
});

// F-2.5.4-008 — debounced autosave endpoint persists content + revision
it('saves builder content through the autosave endpoint', function (): void {
    $response = $this->postJson(route('templates.builder.save', ['uid' => $this->template->uid]), [
        'content_html' => wp10_valid_html(),
        'content_json' => json_encode(['pages' => [['id' => 'p1']]]),
        'google_fonts' => ['https://fonts.googleapis.com/css2?family=Inter'],
    ]);

    $response->assertOk()
        ->assertJson(['saved' => true, 'unsubscribe_ok' => true, 'warning' => null]);

    $this->template->refresh();

    expect($this->template->content_html)->toBe(wp10_valid_html())
        ->and($this->template->content_json)->toContain('p1')
        ->and($this->template->google_fonts)->toBe(['https://fonts.googleapis.com/css2?family=Inter'])
        ->and($this->template->missing_unsubscribe_tag)->toBeFalse()
        ->and($this->template->revisions()->count())->toBe(1);
});

/* ====================================================================== *
 *  F-2.5.4-006 — mobile-specific styling / hide-on-mobile
 * ====================================================================== */

it('emits the hide-on-mobile class and media query into the saved template html', function (): void {
    $html = '<html><head><title>t</title></head><body>'
        .'<table class="hide-mobile"><tr><td>Desktop-only banner</td></tr></table>'
        .'<a href="{UNSUBSCRIBE_URL}">bye</a></body></html>';

    $this->postJson(route('templates.builder.save', ['uid' => $this->template->uid]), [
        'content_html' => $html,
    ])->assertOk();

    $saved = (string) $this->template->refresh()->content_html;

    expect($saved)
        ->toContain('class="hide-mobile"')
        // The real, email-safe media query — it cannot be inlined, so it must
        // be present as a <style> rule in the head.
        ->toContain('@media only screen and (max-width:600px)')
        ->toContain('.hide-mobile{display:none!important')
        ->toContain('mso-hide:all')
        ->and(str_contains($saved, '</style></head>'))->toBeTrue();

    // Idempotent: a second autosave must not stack a duplicate <style> block.
    $this->postJson(route('templates.builder.save', ['uid' => $this->template->uid]), [
        'content_html' => $saved,
    ])->assertOk();

    expect(substr_count((string) $this->template->refresh()->content_html, '.hide-mobile{display:none'))->toBe(1);
});

it('leaves content without a hide-on-mobile block untouched', function (): void {
    $this->postJson(route('templates.builder.save', ['uid' => $this->template->uid]), [
        'content_html' => wp10_valid_html(),
    ])->assertOk();

    expect($this->template->refresh()->content_html)->toBe(wp10_valid_html());
});

it('injects the hide-on-mobile media query into head-less markup too', function (): void {
    // Bare fragment (the builder can emit one) and a body-only document.
    expect(MobileVisibility::apply('<div class="hide-mobile">x</div>'))
        ->toStartWith('<style type="text/css">@media only screen and (max-width:600px)');

    $bodyOnly = MobileVisibility::apply('<body><p class="a hide-mobile b">x</p></body>');

    expect($bodyOnly)->toContain('<body><style type="text/css">@media');
});

// F-2.5.4-008 — only the newest N revisions are kept (default 20)
it('prunes revision history to the configured keep count', function (): void {
    expect((int) config('emp-templates.revisions_keep'))->toBe(20);

    $revisions = app(TemplateRevisions::class);

    for ($i = 0; $i < 23; $i++) {
        $this->template->content_html = '<p>rev '.$i.' {UNSUBSCRIBE_URL}</p>';
        $revisions->record($this->template);
    }

    expect($this->template->revisions()->count())->toBe(20)
        // the newest revision survives, the oldest three are pruned
        ->and($this->template->revisions()->first()->content_html)->toContain('rev 22');
});

// F-2.5.4-008 — restore, and restore is itself undoable
it('restores a revision from the builder page', function (): void {
    $revisions = app(TemplateRevisions::class);

    $this->template->forceFill(['content_html' => '<p>v1 {UNSUBSCRIBE_URL}</p>'])->save();
    $old = $revisions->record($this->template);

    $this->template->forceFill(['content_html' => '<p>v2 {UNSUBSCRIBE_URL}</p>'])->save();
    $revisions->record($this->template);

    Livewire::test(BuildEmailTemplate::class, ['record' => $this->template->getRouteKey()])
        ->call('restoreRevision', $old->id);

    $this->template->refresh();

    expect($this->template->content_html)->toBe('<p>v1 {UNSUBSCRIBE_URL}</p>')
        // pre-restore content was recorded, so the restore can be undone
        ->and($this->template->revisions()->pluck('content_html')->filter(
            fn (?string $html): bool => str_contains((string) $html, 'v2'),
        ))->not->toBeEmpty();
});

// Tenancy — autosave is policy-checked per template
it('rejects autosave for other tenants, gallery rows and unknown uids', function (): void {
    $payload = ['content_html' => wp10_valid_html()];

    // Unknown uid → 404
    $this->postJson(route('templates.builder.save', ['uid' => 'no-such-uid']), $payload)
        ->assertNotFound();

    // Gallery row → 403
    $gallery = app(CurrentCustomer::class)->runAs(
        null,
        fn (): EmailTemplate => EmailTemplate::factory()->gallery()->create(),
    );

    $this->postJson(route('templates.builder.save', ['uid' => $gallery->uid]), $payload)
        ->assertForbidden();

    // Another tenant's template → 403, contents untouched
    $other = Customer::factory()->create();
    $foreign = EmailTemplate::factory()->create([
        'customer_id' => $other->id,
        'content_html' => '<p>original {UNSUBSCRIBE_URL}</p>',
    ]);

    $this->postJson(route('templates.builder.save', ['uid' => $foreign->uid]), $payload)
        ->assertForbidden();

    expect(EmailTemplate::withoutTenancy()->find($foreign->id)->content_html)
        ->toBe('<p>original {UNSUBSCRIBE_URL}</p>');
});

// F-2.5.4-012 — Google Fonts URLs are restricted to allowed hosts
it('rejects font urls outside the allowed hosts', function (): void {
    $this->postJson(route('templates.builder.save', ['uid' => $this->template->uid]), [
        'content_html' => wp10_valid_html(),
        'google_fonts' => ['https://evil.example.com/font.css'],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['google_fonts.0']);
});

// F-2.5.5-006 — unsubscribe check in warn mode flags, in block mode rejects
it('flags a missing unsubscribe tag on autosave in warn mode', function (): void {
    $this->postJson(route('templates.builder.save', ['uid' => $this->template->uid]), [
        'content_html' => '<p>no tag here</p>',
    ])->assertOk()
        ->assertJson(['unsubscribe_ok' => false])
        ->assertJsonPath('warning', __('templates.warnings.missing_unsubscribe'));

    expect($this->template->refresh()->missing_unsubscribe_tag)->toBeTrue();
});

it('blocks a save without unsubscribe tag in block mode', function (): void {
    config(['emp-templates.unsubscribe_check' => 'block']);

    $this->postJson(route('templates.builder.save', ['uid' => $this->template->uid]), [
        'content_html' => '<p>no tag here</p>',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['content_html']);
});

// §2.5.4 — builder image uploads land in the tenant's asset directory
it('stores builder asset uploads under the tenant directory', function (): void {
    Storage::fake('public');

    $response = $this->post(route('templates.builder.assets', ['uid' => $this->template->uid]), [
        'file' => UploadedFile::fake()->image('My Logo.png', 100, 40),
    ]);

    $response->assertOk()->assertJsonStructure(['url', 'path']);

    $path = $response->json('path');
    $expectedDir = 'customers/'.$this->customer->uid.'/templates/'.$this->template->uid;

    expect($path)->toStartWith($expectedDir);

    Storage::disk('public')->assertExists($path);
});

it('rejects non-image builder asset uploads', function (): void {
    Storage::fake('public');

    $this->post(
        route('templates.builder.assets', ['uid' => $this->template->uid]),
        ['file' => UploadedFile::fake()->create('script.php', 10, 'text/x-php')],
        ['Accept' => 'application/json'],
    )->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

// Guests never reach the builder endpoints
it('requires customer authentication on the builder endpoints', function (): void {
    auth('customer')->logout();
    app(CurrentCustomer::class)->set(null);

    $this->postJson(route('templates.builder.save', ['uid' => $this->template->uid]), [
        'content_html' => wp10_valid_html(),
    ])->assertUnauthorized();
});
