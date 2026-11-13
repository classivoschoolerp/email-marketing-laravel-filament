<?php

declare(strict_types=1);

use App\Enums\FormType;
use App\Enums\ListFieldType;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\FieldOption;
use App\Models\Form;
use App\Models\FormField;
use App\Services\Forms\DisplayRules;
use App\Services\Forms\FormEmbedGenerator;
use App\Services\Forms\FormRenderer;
use App\Services\Forms\FormStyles;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Str;

/*
 * §2.7 embed delivery.
 *
 * F-2.7-011 three embed variants, the JS snippet being a real self-contained
 *           loader served from our own route
 * F-2.7-005/006 the loader receives — and evaluates — the saved rules
 * F-2.7-007 the scoped stylesheet travels with it
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $this->form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'name' => 'Exit offer',
        'heading' => 'Wait! 🎁',
        'type' => FormType::Popup,
        'display_rules' => [
            'pages_mode' => DisplayRules::PAGES_CONTAINS,
            'pages' => ['/pricing'],
            'delay_seconds' => 5,
            'scroll_percent' => 50,
            'exit_intent' => true,
            'min_page_views' => 2,
            'devices' => ['desktop'],
            'visitors' => DisplayRules::VISITORS_RETURNING,
        ],
        'frequency_rules' => [
            'hide_days_after_close' => 14,
            'hide_after_submit' => true,
            'hide_after_submit_days' => 0,
            'max_shows_per_visitor' => 3,
        ],
        'styles' => ['button_color' => '#123456', 'corner_radius' => 18],
    ]);
});

it('offers the three embed variants (F-2.7-011)', function (): void {
    $embeds = app(FormEmbedGenerator::class);

    $script = $embeds->script($this->form);
    expect($script)->toContain(route('forms.public.script', ['uid' => $this->form->uid]))
        ->and($script)->toContain('<script');

    $iframe = $embeds->iframe($this->form);
    expect($iframe)->toContain('<iframe')
        ->and($iframe)->toContain(route('forms.public.show', ['uid' => $this->form->uid]));

    $html = $embeds->html($this->form);
    expect($html)->toContain('<form')
        ->and($html)->toContain('action="'.route('forms.public.submit', ['uid' => $this->form->uid]).'"')
        ->and($html)->toContain('name="email"')
        ->and($html)->toContain(PublicSubscribeController::HONEYPOT_FIELD)
        // Raw HTML carries its own styles so it looks right without the loader.
        ->and($html)->toContain('<style>');

    // Embedded forms get a placeholder div; overlays do not need one.
    $embedded = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'type' => FormType::Embedded,
    ]);

    expect($embeds->script($embedded))->toContain('data-emp-form="'.$embedded->uid.'"')
        ->and($script)->not->toContain('data-emp-form="'.$this->form->uid.'"');
});

it('serves a self-contained JavaScript loader from its own route (F-2.7-011)', function (): void {
    $response = $this->get(route('forms.public.script', ['uid' => $this->form->uid]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');

    $body = $response->getContent();

    // A real program, not a stub: it renders, arms triggers and submits.
    expect($body)->toContain('window.EMPForms')
        ->and($body)->toContain('function mount()')
        ->and($body)->toContain('function onSubmit')
        ->and($body)->toContain('addEventListener("mouseout"')   // exit intent
        ->and($body)->toContain('addEventListener("scroll"')     // scroll %
        ->and($body)->toContain('setTimeout(mount')              // delay
        ->and($body)->toContain('localStorage')                  // frequency
        // …and it depends on nothing external.
        ->and($body)->not->toContain('src="http')
        ->and($body)->not->toContain('require(');
});

it('ships the saved display and frequency rules to the loader (F-2.7-005/006)', function (): void {
    $payload = app(FormEmbedGenerator::class)->payload($this->form);

    expect($payload['display'])->toBe([
        'pages_mode' => DisplayRules::PAGES_CONTAINS,
        'pages' => ['/pricing'],
        'delay_seconds' => 5,
        'scroll_percent' => 50,
        'exit_intent' => true,
        'min_page_views' => 2,
        'devices' => ['desktop'],
        'visitors' => DisplayRules::VISITORS_RETURNING,
    ]);

    expect($payload['frequency'])->toBe([
        'hide_days_after_close' => 14,
        'hide_after_submit' => true,
        'hide_after_submit_days' => 0,
        'max_shows_per_visitor' => 3,
    ]);

    expect($payload['submitUrl'])->toBe(route('forms.public.submit', ['uid' => $this->form->uid]))
        ->and($payload['viewUrl'])->toBe(route('forms.public.impression', ['uid' => $this->form->uid]))
        ->and($payload['type'])->toBe('popup')
        // F-2.7-007: the scoped stylesheet travels with the payload.
        ->and($payload['css'])->toContain('#123456')
        ->and($payload['css'])->toContain('.emp-form-'.FormStyles::scope($this->form));

    // The very same JSON is what the served script embeds.
    $body = $this->get(route('forms.public.script', ['uid' => $this->form->uid]))->getContent();

    expect($body)->toContain('"scroll_percent":50')
        ->and($body)->toContain('"hide_days_after_close":14')
        ->and($body)->toContain('"visitors":"returning"');
});

it('forces the exit-intent rule for the exit-intent form type (F-2.7-002/005)', function (): void {
    $form = Form::factory()->type(FormType::ExitIntent)->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'display_rules' => DisplayRules::defaults(),
    ]);

    expect(DisplayRules::for($form)['exit_intent'])->toBeTrue();
});

it('renders every field type into the embed body (F-2.7-003)', function (): void {
    $dropdown = FormField::factory()->create([
        'form_id' => $this->form->id,
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::Dropdown,
        'tag' => 'PLAN', 'label' => 'Plan', 'sort_order' => 0,
    ]);

    FieldOption::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'owner_type' => FormField::class,
        'owner_id' => $dropdown->id,
        'label' => 'Pro 💎', 'value' => 'pro', 'sort_order' => 0,
    ]);

    FormField::factory()->create([
        'form_id' => $this->form->id, 'customer_id' => $this->customer->id,
        'type' => ListFieldType::Textarea, 'tag' => 'NOTES', 'label' => 'Notes', 'sort_order' => 1,
    ]);
    FormField::factory()->create([
        'form_id' => $this->form->id, 'customer_id' => $this->customer->id,
        'type' => ListFieldType::Country, 'tag' => 'COUNTRY', 'label' => 'Country', 'sort_order' => 2,
    ]);
    FormField::factory()->create([
        'form_id' => $this->form->id, 'customer_id' => $this->customer->id,
        'type' => ListFieldType::Hidden, 'tag' => 'CAMPAIGN', 'label' => 'Campaign',
        'default_value' => 'spring', 'sort_order' => 3,
    ]);

    $body = app(FormRenderer::class)->body($this->form->refresh());

    expect($body)->toContain('<select')
        ->and($body)->toContain('value="pro"')
        ->and($body)->toContain('Pro 💎')
        ->and($body)->toContain('<textarea')
        ->and($body)->toContain('name="COUNTRY"')
        ->and($body)->toContain('<input type="hidden" name="CAMPAIGN" value="spring">')
        ->and($body)->toContain('name="'.FormRenderer::SOURCE_FIELD.'"')
        ->and($body)->toContain('Wait! 🎁');
});

it('serves the hosted page as a bare, embeddable document', function (): void {
    $response = $this->get(route('forms.public.show', ['uid' => $this->form->uid]));

    $response->assertOk()
        ->assertSee('<form', false)
        ->assertSee('noindex', false)
        // No site chrome: an iframe must show the form and nothing else.
        ->assertDontSee('emp-company-footer', false);
});

it('keeps another tenant\'s embed endpoints unreachable by guessing', function (): void {
    $other = Customer::factory()->create();

    app(CurrentCustomer::class)->runAs($other, function () use ($other): void {
        EmailList::factory()->create(['customer_id' => $other->id]);
    });

    foreach (['forms.public.script', 'forms.public.show'] as $route) {
        $this->get(route($route, ['uid' => (string) Str::uuid()]))->assertNotFound();
    }

    $this->post(route('forms.public.impression', ['uid' => (string) Str::uuid()]))
        ->assertNotFound();
});
