<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\CampaignResource\Pages\CreateCampaign;
use App\Filament\Customer\Resources\CampaignResource\Pages\EditCampaign;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Models\SpintaxSnippet;
use App\Services\Templates\TemplateZipImporter;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// §2.3.3 — campaign wizard step 2: content sources, editors, builder
// endpoints, plain text, save-as-template.

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
});

function wp11_content_form(array $overrides = []): array
{
    return array_merge([
        'name' => 'Content campaign',
        'type' => 'regular',
        'email_list_id' => test()->list->id,
        'subject' => 'Subject',
        'builder' => 'html',
    ], $overrides);
}

// F-2.3.3-001 — start from one of my templates.
it('copies content from a chosen template', function (): void {
    $template = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id,
        'content_html' => '<html><body><p>Template body {UNSUBSCRIBE_URL}</p></body></html>',
    ]);

    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_content_form([
            'content_source' => 'template',
            'source_template_id' => $template->id,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $campaign = Campaign::query()->firstOrFail();

    expect($campaign->content_html)->toContain('Template body')
        ->and($campaign->template_id)->toBe($template->id);
});

// F-2.3.3-001 — start from a previous campaign.
it('copies content from a previous campaign', function (): void {
    $previous = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'content_html' => '<p>Previous body {UNSUBSCRIBE_URL}</p>',
        'plain_text' => 'Previous body',
    ]);

    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_content_form([
            'content_source' => 'campaign',
            'source_campaign_id' => $previous->id,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $campaign = Campaign::query()->whereKeyNot($previous->id)->firstOrFail();

    expect($campaign->content_html)->toContain('Previous body')
        ->and($campaign->plain_text)->toBe('Previous body');
});

// F-2.3.3-001 — blank starts with the starter scaffold.
it('starts blank campaigns with the starter html', function (): void {
    Livewire::test(CreateCampaign::class)
        ->fillForm(wp11_content_form(['content_source' => 'blank']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Campaign::query()->firstOrFail()->content_html)->toContain('{UNSUBSCRIBE_URL}');
});

// F-2.3.3-005 — ZIP import reuses the WP-10 TemplateZipImporter.
it('imports campaign content from a zip archive', function (): void {
    Storage::fake('public');

    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    $zipPath = tempnam(sys_get_temp_dir(), 'wp11-zip-');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('index.html', '<html><body><p>Zipped 内容</p><img src="images/logo.png"></body></html>');
    $zip->addFromString('images/logo.png', 'fake-png-bytes');
    $zip->close();

    app(TemplateZipImporter::class)->import($campaign, $zipPath);

    $campaign->refresh();

    expect($campaign->content_html)->toContain('Zipped 内容')
        // Image extracted into the campaign's asset dir + reference rewritten.
        ->and($campaign->content_html)->not->toContain('src="images/logo.png"');

    Storage::disk('public')->assertExists($campaign->assetDirectory().'/images/logo.png');

    unlink($zipPath);
});

// F-2.3.3-005 — URL import reuses the WP-10 TemplateUrlImporter.
it('imports campaign content from a url on edit', function (): void {
    Http::fake([
        'https://source.test/*' => Http::response(
            '<html><body><p>Fetched page</p><img src="images/pic.png"></body></html>'
        ),
    ]);

    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->fillForm([
            'content_source' => 'url',
            'source_url' => 'https://source.test/newsletter.html',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $campaign->refresh();

    expect($campaign->content_html)->toContain('Fetched page')
        // Relative refs absolutised against the source origin.
        ->and($campaign->content_html)->toContain('https://source.test/images/pic.png');
});

// F-2.3.3-002 — GrapesJS autosave endpoint (campaign builder page).
it('saves builder content through the campaign save endpoint', function (): void {
    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    $this->postJson(route('campaigns.builder.save', ['uid' => $campaign->uid]), [
        'content_html' => '<html><body><p>Built {UNSUBSCRIBE_URL}</p></body></html>',
        'content_json' => json_encode(['pages' => []]),
    ])
        ->assertOk()
        ->assertJson(['saved' => true, 'unsubscribe_ok' => true]);

    expect($campaign->refresh()->content_html)->toContain('Built');
});

// F-2.3.3-015 — missing unsubscribe tag returns a warning on save.
it('warns when saved builder content lacks the unsubscribe tag', function (): void {
    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    $this->postJson(route('campaigns.builder.save', ['uid' => $campaign->uid]), [
        'content_html' => '<html><body><p>No tag here</p></body></html>',
    ])
        ->assertOk()
        ->assertJson(['unsubscribe_ok' => false])
        ->assertJsonPath('warning', __('campaigns.warnings.missing_unsubscribe'));
});

// Tenancy + editability on the builder endpoints.
it('denies builder saves cross-tenant and once sending', function (): void {
    $other = Customer::factory()->create();
    $foreign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): Campaign => Campaign::factory()->create(['customer_id' => $other->id]),
    );

    $this->postJson(route('campaigns.builder.save', ['uid' => $foreign->uid]), [
        'content_html' => '<p>hijack</p>',
    ])->assertForbidden();

    $sending = Campaign::factory()->sending()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    $this->postJson(route('campaigns.builder.save', ['uid' => $sending->uid]), [
        'content_html' => '<p>too late</p>',
    ])->assertForbidden();

    $this->postJson(route('campaigns.builder.save', ['uid' => 'nope']), [
        'content_html' => '<p>x</p>',
    ])->assertNotFound();
});

// F-2.3.3-016 — image upload lands in the campaign media directory.
it('uploads builder assets into the campaign asset directory', function (): void {
    Storage::fake('public');

    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    $response = $this->post(route('campaigns.builder.assets', ['uid' => $campaign->uid]), [
        'file' => UploadedFile::fake()->image('hero.png', 600, 200),
    ])->assertOk();

    $path = $response->json('path');

    expect($path)->toContain('customers/'.$this->customer->uid.'/campaigns/'.$campaign->uid);
    Storage::disk('public')->assertExists($path);
});

// F-2.3.3-022 — spintax preview endpoint returns count + samples.
it('returns spintax permutations and samples from the preview endpoint', function (): void {
    $this->postJson(route('campaigns.spintax.preview'), [
        'text' => '{Hi|Hello|Hey} {SUBSCRIBER_NAME}',
        'samples' => 3,
    ])
        ->assertOk()
        ->assertJsonPath('permutations', 3)
        ->assertJsonCount(3, 'samples');
});

// F-2.3.3-006 — plain-text generation action fills the override field.
it('generates the plain text from html via the form action', function (): void {
    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'content_html' => '<p>Hello <a href="https://x.test/a">link</a></p><p>{UNSUBSCRIBE_URL}</p>',
    ]);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callFormComponentAction('plain_text', 'generate_plain_text')
        ->assertFormSet(fn (array $state): bool => str_contains((string) $state['plain_text'], 'link (https://x.test/a)'));
});

// F-2.3.3-018 — save as template.
it('saves the campaign content as a template', function (): void {
    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'content_html' => '<p>Reusable {UNSUBSCRIBE_URL}</p>',
    ]);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('save_as_template', data: ['name' => 'From campaign']);

    $template = EmailTemplate::query()->where('name', 'From campaign')->first();

    expect($template)->not->toBeNull()
        ->and($template->customer_id)->toBe($this->customer->id)
        ->and($template->content_html)->toContain('Reusable');
});

// F-2.3.3-002 — the GrapesJS builder page renders with the campaign config.
it('renders the campaign content builder page with the save endpoints', function (): void {
    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'builder' => 'grapesjs',
    ]);

    $this->get(CampaignResource::getUrl('content', ['record' => $campaign]))
        ->assertOk()
        ->assertSee('template-builder-config')
        // @json escapes slashes — assert on the config key + campaign uid.
        ->assertSee('saveUrl')
        ->assertSee($campaign->uid)
        // Merge tags include the enabled template tags (D-22).
        ->assertSee('SUBSCRIBER_EMAIL');

    // Not reachable once sending (edit-until-sending).
    $sending = Campaign::factory()->sending()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'builder' => 'grapesjs',
    ]);

    $this->get(CampaignResource::getUrl('content', ['record' => $sending]))
        ->assertForbidden();
});

// F-2.3.3-023 — snippets scoped per tenant in the insert dropdown source.
it('never exposes another tenants spintax snippets', function (): void {
    $other = Customer::factory()->create();
    app(CurrentCustomer::class)->runAs(
        $other,
        fn () => SpintaxSnippet::factory()->create([
            'customer_id' => $other->id,
            'name' => 'Foreign snippet',
        ]),
    );

    expect(SpintaxSnippet::query()->count())->toBe(0);
});
