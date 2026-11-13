<?php

declare(strict_types=1);

use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Filament\Customer\Resources\EmailTemplateResource\Pages\CreateEmailTemplate;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Services\Preferences\CustomerPreferences;
use App\Services\Templates\Builders\BuilderRegistry;
use App\Services\Templates\Builders\StripoDriver;
use App\Services\Templates\TemplateSettingsStore;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

// F-2.5.3-002 / F-2.5.3-006 — GrapesJS, raw HTML and rich text always offered
it('always offers grapesjs, raw html and rich text builders', function (): void {
    $options = app(BuilderRegistry::class)->options();

    expect($options)->toHaveKeys(['grapesjs', 'html', 'richtext'])
        ->and($options)->not->toHaveKey('stripo')
        ->and($options)->not->toHaveKey('unlayer');
});

// F-2.5.3-003 — Stripo plugs in via the driver interface once credentials exist
it('offers stripo only when plugin credentials are configured', function (): void {
    expect(app(StripoDriver::class)->isAvailable())->toBeFalse();

    config([
        'emp-templates.stripo.plugin_id' => 'plugin-id',
        'emp-templates.stripo.secret_key' => 'secret',
    ]);

    $registry = new BuilderRegistry;

    expect($registry->get('stripo')->isAvailable())->toBeTrue()
        ->and($registry->options())->toHaveKey('stripo');
});

// F-2.5.3-005 — resolution order: customer preference → operator setting → config
it('resolves the default builder from config, operator setting then customer preference', function (): void {
    // 1. config fallback
    expect(app(BuilderRegistry::class)->defaultFor($this->customer))->toBe(TemplateBuilder::GrapesJs);

    // 2. operator override
    app(TemplateSettingsStore::class)->set(BuilderRegistry::OPERATOR_SETTING_KEY, 'html');

    expect(app(BuilderRegistry::class)->defaultFor($this->customer))->toBe(TemplateBuilder::Html)
        ->and(app(BuilderRegistry::class)->operatorDefault())->toBe(TemplateBuilder::Html);

    // 3. customer preference wins over the operator override
    app(CustomerPreferences::class)->set(
        $this->customer,
        BuilderRegistry::CUSTOMER_PREFERENCE_KEY,
        'richtext',
    );

    expect(app(BuilderRegistry::class)->defaultFor($this->customer))->toBe(TemplateBuilder::RichText);
});

// F-2.5.3-005 — unavailable choices fall back to the P1 builder (D-11)
it('falls back to grapesjs when the preferred builder is unavailable', function (): void {
    app(CustomerPreferences::class)->set(
        $this->customer,
        BuilderRegistry::CUSTOMER_PREFERENCE_KEY,
        'stripo', // no credentials → unavailable
    );

    expect(app(BuilderRegistry::class)->defaultFor($this->customer))->toBe(TemplateBuilder::GrapesJs);
});

// F-2.5.3-005 — the create form pre-selects the customer's default
it('preselects the customer default builder on the create form', function (): void {
    app(CustomerPreferences::class)->set(
        $this->customer,
        BuilderRegistry::CUSTOMER_PREFERENCE_KEY,
        'richtext',
    );

    Livewire::test(CreateEmailTemplate::class)
        ->assertFormSet(['builder' => 'richtext']);
});

// §2.5.3 — the builder is chosen per template and drives the edit surface
it('creates a template with the chosen builder and starter unsubscribe content', function (): void {
    Livewire::test(CreateEmailTemplate::class)
        ->fillForm([
            'name' => 'My HTML template',
            'builder' => 'html',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = EmailTemplate::query()->where('name', 'My HTML template')->first();

    expect($template)->not->toBeNull()
        ->and($template->builder)->toBe(TemplateBuilder::Html)
        ->and($template->content_html)->toContain('{UNSUBSCRIBE_URL}')
        ->and($template->revisions()->count())->toBe(1);
});

// §2.5.3 — invalid builder keys are rejected
it('rejects unknown builder keys', function (): void {
    Livewire::test(CreateEmailTemplate::class)
        ->fillForm([
            'name' => 'Bad builder',
            'builder' => 'not-a-builder',
        ])
        ->call('create')
        ->assertHasFormErrors(['builder']);
});

// F-2.5.3-002 — grapesjs templates edit in the builder page
it('sends grapesjs templates to the builder page and html templates to the edit form', function (): void {
    $grapes = EmailTemplate::factory()->grapesjs()->create(['customer_id' => $this->customer->id]);
    $html = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);

    $registry = app(BuilderRegistry::class);

    expect($registry->get(TemplateBuilder::GrapesJs)->editUrl($grapes))
        ->toBe(EmailTemplateResource::getUrl('builder', ['record' => $grapes], panel: 'customer'))
        ->and($registry->get(TemplateBuilder::Html)->editUrl($html))
        ->toBe(EmailTemplateResource::getUrl('edit', ['record' => $html], panel: 'customer'));

    // Opening the edit page of a grapesjs template redirects to the builder.
    $this->get(EmailTemplateResource::getUrl('edit', ['record' => $grapes], panel: 'customer'))
        ->assertRedirect(EmailTemplateResource::getUrl('builder', ['record' => $grapes], panel: 'customer'));

    // The raw HTML editor stays reachable (F-2.5.3-006).
    $this->get(EmailTemplateResource::getUrl('edit', ['record' => $html], panel: 'customer'))
        ->assertOk();
});
