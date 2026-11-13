<?php

declare(strict_types=1);

use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Services\Templates\Builders\BuilderRegistry;
use App\Services\Templates\Builders\StripoDriver;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

/*
 * WP-26 §2.5.3 F-2.5.3-003 — Stripo plugin adapter against the documented
 * plugin API: server-side token exchange (secret key never leaves the
 * server), the editor init payload, and the availability gate.
 *
 * Live verification (a real Stripo account issuing a real token and the
 * editor actually compiling HTML) is an e2e-checklist item.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->template = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id,
        'builder' => TemplateBuilder::Stripo,
        'content_html' => '<html><body><p>Hello</p></body></html>',
    ]);
});

function wp26_configureStripo(): void
{
    config([
        'emp-templates.stripo.plugin_id' => 'plugin-123',
        'emp-templates.stripo.secret_key' => 'secret-456',
    ]);

    cache()->flush();
}

it('stays unavailable and unreachable without credentials', function (): void {
    expect(app(StripoDriver::class)->isAvailable())->toBeFalse()
        ->and(app(BuilderRegistry::class)->options())->not->toHaveKey('stripo');

    // The picker link falls back to the raw-HTML surface — never a 404.
    expect(app(StripoDriver::class)->editUrl($this->template))
        ->toBe(EmailTemplateResource::getUrl('edit', ['record' => $this->template], panel: 'customer'));

    $this->get(EmailTemplateResource::getUrl('stripo', ['record' => $this->template], panel: 'customer'))
        ->assertForbidden();
});

it('exchanges the plugin credentials for a token server-side', function (): void {
    wp26_configureStripo();

    Http::fake(['plugins.stripo.email/api/v1/auth' => Http::response(['token' => 'tok_abc'])]);

    expect(app(StripoDriver::class)->authToken($this->customer))->toBe('tok_abc');

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/api/v1/auth')
            && $request['pluginId'] === 'plugin-123'
            && $request['secretKey'] === 'secret-456'
            && $request['userId'] === 'customer-'.$this->customer->uid;
    });
});

it('caches the token so re-opening the editor does not re-auth', function (): void {
    wp26_configureStripo();

    Http::fake(['plugins.stripo.email/api/v1/auth' => Http::response(['token' => 'tok_cached'])]);

    $driver = app(StripoDriver::class);

    expect($driver->authToken($this->customer))->toBe('tok_cached')
        ->and($driver->authToken($this->customer))->toBe('tok_cached');

    Http::assertSentCount(1);
});

it('fails loudly when Stripo refuses the credentials', function (): void {
    wp26_configureStripo();

    Http::fake(['plugins.stripo.email/*' => Http::response(['message' => 'bad credentials'], 401)]);

    expect(fn () => app(StripoDriver::class)->authToken($this->customer))
        ->toThrow(RuntimeException::class, 'Stripo auth failed');
});

it('builds the documented editor init payload', function (): void {
    wp26_configureStripo();

    Http::fake(['plugins.stripo.email/*' => Http::response(['token' => 'tok_init'])]);

    $config = app(StripoDriver::class)->editorConfig($this->template, $this->customer);

    expect($config['settingsId'])->toBe('stripoSettingsContainer')
        ->and($config['previewId'])->toBe('stripoPreviewContainer')
        ->and($config['apiRequestData']['emailId'])->toBe($this->template->uid)
        ->and($config['html'])->toBe($this->template->content_html)
        ->and($config['token'])->toBe('tok_init')
        ->and($config['scriptUrl'])->toContain('stripo.js')
        ->and($config['saveUrl'])->toBe(route('templates.builder.save', ['uid' => $this->template->uid]));
});

it('becomes selectable and renders the editor container once configured', function (): void {
    wp26_configureStripo();

    Http::fake(['plugins.stripo.email/*' => Http::response(['token' => 'tok_page'])]);

    $registry = new BuilderRegistry;

    expect($registry->options())->toHaveKey('stripo')
        ->and($registry->get('stripo')->editUrl($this->template))
        ->toBe(EmailTemplateResource::getUrl('stripo', ['record' => $this->template], panel: 'customer'));

    $this->get(EmailTemplateResource::getUrl('stripo', ['record' => $this->template], panel: 'customer'))
        ->assertOk()
        ->assertSee('stripoSettingsContainer', false)
        ->assertSee('stripoPreviewContainer', false)
        // The token reaches the browser; the SECRET KEY never does.
        ->assertSee('tok_page', false)
        ->assertDontSee('secret-456', false);
});

it('refuses the editor page for another tenant\'s template', function (): void {
    wp26_configureStripo();

    Http::fake(['plugins.stripo.email/*' => Http::response(['token' => 'tok_x'])]);

    $other = Customer::factory()->create();
    $theirs = EmailTemplate::factory()->create(['customer_id' => $other->id]);

    $this->get(EmailTemplateResource::getUrl('stripo', ['record' => $theirs], panel: 'customer'))
        ->assertNotFound();
});
