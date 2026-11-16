<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Enums\ListFieldType;
use App\Filament\Customer\Resources\FormResource\Pages\ManageFormFields;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\Subscriber;
use App\Services\Forms\FormEmbedGenerator;
use App\Services\Forms\FormRenderer;
use App\Services\Forms\FormSteps;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;

/*
 * F-2.7-004 — multi-step forms.
 *
 * The behaviours proven here, in order:
 *   • one <fieldset> per step, email on step 1, consent + captcha on the last;
 *   • it WORKS WITH JAVASCRIPT DISABLED — every step is rendered visible, the
 *     step controls are `hidden` until a script un-hides them, and there is
 *     exactly one submit button;
 *   • per-step validation reports a bad step-1 field before step 3;
 *   • the final submit still validates EVERY field, so a hand-crafted POST
 *     that skips a step is rejected;
 *   • per-step validation persists NOTHING (no partial progress, D-96/consent);
 *   • exactly one form_submitted event, on the final submit.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'captcha_enabled' => false,
        'multi_step' => true,
        'steps' => [
            'show_progress' => true,
            'items' => [
                ['title' => 'Your address', 'description' => 'Where do we write?', 'next_label' => 'Continue'],
                ['title' => 'About you', 'description' => '', 'next_label' => ''],
                ['title' => 'Finish', 'description' => '', 'next_label' => ''],
            ],
        ],
    ]);

    $this->step2 = FormField::factory()->step(2)->create([
        'form_id' => $this->form->id,
        'customer_id' => $this->customer->id,
        'tag' => 'FNAME',
        'label' => 'First name',
        'required' => true,
        'sort_order' => 0,
    ]);

    $this->step3 = FormField::factory()->step(3)->create([
        'form_id' => $this->form->id,
        'customer_id' => $this->customer->id,
        'tag' => 'COMPANY',
        'label' => 'Company',
        'required' => false,
        'sort_order' => 0,
    ]);
});

it('renders one fieldset per step with a progress indicator (F-2.7-004)', function (): void {
    $body = app(FormRenderer::class)->body($this->form->refresh());

    expect(substr_count($body, 'data-emp-step="'))->toBe(3)
        ->and($body)->toContain('data-emp-step="1"')
        ->and($body)->toContain('data-emp-step="2"')
        ->and($body)->toContain('data-emp-step="3"')
        ->and($body)->toContain('Your address')
        ->and($body)->toContain('Where do we write?')
        // Progress indicator, starting on step 1 of 3.
        ->and($body)->toContain('data-emp-progress-bar')
        ->and($body)->toContain('Step 1 of 3')
        // The customer's own "next" label is used where they set one.
        ->and($body)->toContain('Continue')
        ->and($body)->toContain(__('forms.public.next'))
        ->and($body)->toContain(__('forms.public.back'));

    // The email input is on step 1 and each field on its own step.
    $step1 = substr($body, (int) strpos($body, 'data-emp-step="1"'), (int) strpos($body, 'data-emp-step="2"') - (int) strpos($body, 'data-emp-step="1"'));
    $step2 = substr($body, (int) strpos($body, 'data-emp-step="2"'), (int) strpos($body, 'data-emp-step="3"') - (int) strpos($body, 'data-emp-step="2"'));

    expect($step1)->toContain('name="email"')
        ->and($step1)->not->toContain('name="FNAME"')
        ->and($step2)->toContain('name="FNAME"')
        ->and($step2)->not->toContain('name="COMPANY"');
});

it('puts consent on the LAST step and keeps one submit button', function (): void {
    $this->form->forceFill([
        'consent_enabled' => true,
        'consent_required' => true,
        'consent_text' => 'I agree.',
    ])->save();

    $body = app(FormRenderer::class)->body($this->form->refresh());

    $lastStepAt = (int) strpos($body, 'data-emp-step="3"');
    $consentAt = (int) strpos($body, FormRenderer::CONSENT_FIELD);

    expect($consentAt)->toBeGreaterThan($lastStepAt)
        // One and only one submit control, and it lives OUTSIDE the fieldsets
        // so it is reachable when no script pages the form.
        ->and(substr_count($body, 'type="submit"'))->toBe(1)
        ->and((int) strpos($body, 'type="submit"'))->toBeGreaterThan((int) strrpos($body, '</fieldset>'));
});

it('degrades to one long working form with JavaScript disabled (F-2.7-004)', function (): void {
    $html = $this->get(route('forms.public.show', ['uid' => $this->form->uid]))
        ->assertOk()
        ->getContent();

    // Nothing hides a step server-side: no inline display:none, and the
    // stylesheet carries no rule that could hide one either.
    expect($html)->not->toContain('emp-form__step" style="display:none')
        ->and($html)->not->toContain('.emp-form__step{display:none')
        // The step controls are inert until a script un-hides them.
        ->and($html)->toContain('data-emp-nav hidden')
        ->and(substr_count($html, 'data-emp-nav hidden'))->toBe(3);

    // And a plain, whole-form POST — what a scriptless browser sends — is
    // accepted in one shot.
    $this->post(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'noscript@example.test',
        'FNAME' => 'Ada',
        'COMPANY' => 'Analytical Engines',
    ])->assertOk();

    $submission = FormSubmission::withoutTenancy()->where('email', 'noscript@example.test')->firstOrFail();

    expect($submission->data['FNAME'])->toBe('Ada')
        ->and($submission->data['COMPANY'])->toBe('Analytical Engines');
});

it('validates a single step through the step endpoint (F-2.7-004)', function (): void {
    // Step 1 owns the email address and nothing else.
    $this->postJson(route('forms.public.step', ['uid' => $this->form->uid]), [
        FormRenderer::STEP_FIELD => 1,
        'email' => 'not-an-address',
    ])->assertStatus(422)->assertJsonPath('ok', false)->assertJsonStructure(['errors' => ['email']]);

    $this->postJson(route('forms.public.step', ['uid' => $this->form->uid]), [
        FormRenderer::STEP_FIELD => 1,
        'email' => 'ok@example.test',
    ])->assertOk()->assertExactJson(['ok' => true]);

    // Step 2's required field is NOT enforced while leaving step 1 — that is
    // the whole point of paging.
    $this->postJson(route('forms.public.step', ['uid' => $this->form->uid]), [
        FormRenderer::STEP_FIELD => 2,
        'email' => 'ok@example.test',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['FNAME']]);

    $this->postJson(route('forms.public.step', ['uid' => $this->form->uid]), [
        FormRenderer::STEP_FIELD => 2,
        'FNAME' => 'Ada',
    ])->assertOk();
});

it('persists nothing at all while stepping — no partial progress', function (): void {
    $this->postJson(route('forms.public.step', ['uid' => $this->form->uid]), [
        FormRenderer::STEP_FIELD => 1,
        'email' => 'halfway@example.test',
    ])->assertOk();

    expect(Subscriber::withoutTenancy()->count())->toBe(0)
        ->and(FormSubmission::withoutTenancy()->count())->toBe(0)
        ->and(ConsentEvent::withoutTenancy()->count())->toBe(0);

    // Nor is the step call counted as a conversion.
    expect(app(App\Services\Forms\FormAnalytics::class)->summary($this->form))->toBe([
        'views' => 0,
        'submissions' => 0,
        'conversion_rate' => 0.0,
    ]);
});

it('still validates EVERY field on the final submit (a skipped step is rejected)', function (): void {
    // A hand-crafted POST carrying only step 1 — exactly what a client that
    // skips straight past the paging would send.
    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'skipper@example.test',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['FNAME']]);

    expect(Subscriber::withoutTenancy()->count())->toBe(0)
        ->and(FormSubmission::withoutTenancy()->count())->toBe(0);

    // Passing the per-step call for step 1 changes nothing about that.
    $this->postJson(route('forms.public.step', ['uid' => $this->form->uid]), [
        FormRenderer::STEP_FIELD => 1,
        'email' => 'skipper@example.test',
    ])->assertOk();

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'skipper@example.test',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['FNAME']]);

    expect(FormSubmission::withoutTenancy()->count())->toBe(0);
});

it('clamps a field pointing past the last step onto the last step, never dropping it', function (): void {
    // Steps 4 and 5 were deleted from the form; the field still points at 5.
    $this->step3->forceFill(['step' => 5, 'required' => true])->save();

    $body = app(FormRenderer::class)->body($this->form->refresh());
    $lastStepAt = (int) strpos($body, 'data-emp-step="3"');

    expect($body)->toContain('name="COMPANY"')
        ->and((int) strpos($body, 'name="COMPANY"'))->toBeGreaterThan($lastStepAt);

    // …and the per-step validator agrees with the renderer about where it is.
    $this->postJson(route('forms.public.step', ['uid' => $this->form->uid]), [
        FormRenderer::STEP_FIELD => 3,
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['COMPANY']]);

    expect(FormSteps::stepOf($this->step3->refresh(), 3))->toBe(3);
});

it('raises exactly one form_submitted event, on the final submit (D-96)', function (): void {
    $customer = $this->customer;
    Fx::actingAsCustomer($customer);

    $automation = Fx::automation(
        $customer,
        [
            Fx::node('t1', AutomationNodeType::TriggerFormSubmitted, ['list_id' => $this->list->id], ['next' => 'x1']),
            Fx::node('x1', AutomationNodeType::LogicExit, [], []),
        ],
        ['reentry_mode' => Automation::REENTRY_MULTIPLE],
    );

    // Two step calls then the real submit.
    $this->postJson(route('forms.public.step', ['uid' => $this->form->uid]), [
        FormRenderer::STEP_FIELD => 1, 'email' => 'stepper@example.test',
    ])->assertOk();

    $this->postJson(route('forms.public.step', ['uid' => $this->form->uid]), [
        FormRenderer::STEP_FIELD => 2, 'FNAME' => 'Ada',
    ])->assertOk();

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'stepper@example.test', 'FNAME' => 'Ada', 'COMPANY' => 'AE',
    ])->assertOk();

    expect(AutomationRun::withoutTenancy()->where('automation_id', $automation->id)->count())->toBe(1);
});

it('never consults tenancy on the step endpoint (D-95)', function (): void {
    $other = Customer::factory()->create();

    // A foreign tenant bound as the current customer must not change the
    // outcome of a public step call by one byte.
    $foreign = app(CurrentCustomer::class)->runAs($other, fn (): array => $this->postJson(
        route('forms.public.step', ['uid' => $this->form->uid]),
        [FormRenderer::STEP_FIELD => 2, 'FNAME' => 'Ada'],
    )->assertOk()->json());

    expect($foreign)->toBe(['ok' => true]);

    app(CurrentCustomer::class)->runAs($other, function (): void {
        $this->postJson(route('forms.public.step', ['uid' => $this->form->uid]), [
            FormRenderer::STEP_FIELD => 2,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['FNAME']]);
    });

    // A uid nobody owns is a 404, never a leak.
    $this->postJson(route('forms.public.step', ['uid' => (string) Str::uuid()]), [
        FormRenderer::STEP_FIELD => 1,
    ])->assertNotFound();
});

it('ships the step paging program and its endpoint to the embed loader', function (): void {
    $payload = app(FormEmbedGenerator::class)->payload($this->form->refresh());

    expect($payload['steps'])->toBe(3)
        ->and($payload['stepUrl'])->toBe(route('forms.public.step', ['uid' => $this->form->uid]));

    $body = $this->get(route('forms.public.script', ['uid' => $this->form->uid]))->getContent();

    expect($body)->toContain('function empFormSteps')
        ->and($body)->toContain('data-emp-next')
        ->and($body)->toContain('data-emp-back')
        // …and it still depends on nothing external.
        ->and($body)->not->toContain('require(');
});

it('assigns a field to a step from the builder page (F-2.7-004)', function (): void {
    Fx::actingAsCustomer($this->customer);

    Livewire::test(ManageFormFields::class, ['record' => $this->form->getKey()])
        ->assertOk()
        ->callTableAction('create', data: [
            'type' => ListFieldType::Phone->value,
            'label' => 'Phone number',
            'tag' => 'phone',
            'step' => 3,
        ])
        ->assertHasNoTableActionErrors();

    $field = FormField::query()->where('tag', 'PHONE')->firstOrFail();

    expect((int) $field->step)->toBe(3)
        ->and((int) $field->customer_id)->toBe((int) $this->customer->id);

    // The new input renders on step 3, next to the other one.
    $body = app(FormRenderer::class)->body($this->form->refresh());

    expect((int) strpos($body, 'name="PHONE"'))->toBeGreaterThan((int) strpos($body, 'data-emp-step="3"'));

    // Step numbers offered are exactly the steps the form defines.
    expect(array_keys(FormSteps::options($this->form)))->toBe([1, 2, 3]);

    // A single-step form offers no step control at all, and a field created
    // there lands on step 1 by the column default — the pre-WP-46 behaviour.
    $plain = Form::factory()->create(['customer_id' => $this->customer->id, 'list_id' => $this->list->id]);

    expect(FormSteps::enabled($plain))->toBeFalse()
        ->and(FormSteps::count($plain))->toBe(1);

    Livewire::test(ManageFormFields::class, ['record' => $plain->getKey()])
        ->assertOk()
        ->callTableAction('create', data: [
            'type' => ListFieldType::Text->value,
            'label' => 'City',
            'tag' => 'city',
        ])
        ->assertHasNoTableActionErrors();

    expect((int) FormField::query()->where('form_id', $plain->id)->where('tag', 'CITY')->value('step'))->toBe(1);
});

it('leaves a single-step form byte-identical to before (no regression)', function (): void {
    $plain = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'captcha_enabled' => false,
    ]);

    $body = app(FormRenderer::class)->body($plain);

    expect($body)->toContain('<div class="emp-form__body">')
        ->and($body)->not->toContain('data-emp-step')
        ->and($body)->not->toContain('data-emp-progress')
        ->and($body)->toContain('name="email"');

    // A "multi-step" form with a single step is a normal form, not a paged one.
    $plain->forceFill(['multi_step' => true, 'steps' => ['show_progress' => true, 'items' => [['title' => 'Only']]]])->save();

    expect(FormSteps::enabled($plain->refresh()))->toBeFalse()
        ->and(app(FormRenderer::class)->body($plain))->not->toContain('data-emp-step');
});
