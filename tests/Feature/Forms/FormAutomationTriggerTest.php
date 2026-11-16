<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Enums\SubscriberSource;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Form;
use App\Models\Subscriber;
use App\Services\Automations\AutomationEventDispatcher;
use App\Services\Automations\AutomationEvents;
use App\Services\Subscribers\SubscriberTagService;
use App\Support\Tenancy\CurrentCustomer;
use Tests\Support\AutomationFixtures as Fx;

/*
 * F-2.4.2-011 "submitted a form / landing page" — closed end to end by the
 * §2.7 module.
 *
 * A public form submit must enrol the subscriber in an automation whose
 * trigger is "form submitted", the trigger must be able to name ONE form,
 * and exactly one event may reach the bus even though creating the
 * subscriber also makes the WP-21 model hook fire its form-sourced
 * approximation.
 */

beforeEach(function (): void {
    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $this->form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'name' => 'Footer signup',
    ]);
});

/** trigger → add tag → exit, so a fired trigger leaves a visible mark. */
function formTriggerFlow(array $config, string $tag): array
{
    return [
        Fx::node('t1', AutomationNodeType::TriggerFormSubmitted, $config, ['next' => 'a1']),
        Fx::node('a1', AutomationNodeType::ActionAddTag, ['tag' => $tag], ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ];
}

function formRunCount(Automation $automation): int
{
    return AutomationRun::withoutTenancy()->where('automation_id', $automation->id)->count();
}

it('enrols and runs the automation from a real public form submit (F-2.4.2-011)', function (): void {
    $automation = Fx::automation($this->customer, formTriggerFlow(
        ['list_id' => $this->list->id, 'form_id' => $this->form->id],
        'from-footer-form',
    ));

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'lead@example.test',
    ])->assertOk();

    $subscriber = Subscriber::withoutTenancy()->where('email', 'lead@example.test')->firstOrFail();

    expect(formRunCount($automation))->toBe(1)
        ->and(app(SubscriberTagService::class)->has($subscriber, 'from-footer-form'))->toBeTrue();
});

it('raises exactly one form_submitted event per submission', function (): void {
    // Re-entry "multiple" would happily run twice; the count proves the
    // WP-21 approximation is muted while the real producer runs.
    $automation = Fx::automation(
        $this->customer,
        formTriggerFlow(['list_id' => $this->list->id], 'once-only'),
        ['reentry_mode' => Automation::REENTRY_MULTIPLE],
    );

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'single@example.test',
    ])->assertOk();

    expect(formRunCount($automation))->toBe(1);
});

it('filters the trigger to one specific form (F-2.4.2-011)', function (): void {
    $other = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'name' => 'Sidebar signup',
    ]);

    $automation = Fx::automation($this->customer, formTriggerFlow(
        ['list_id' => $this->list->id, 'form_id' => $this->form->id],
        'footer-only',
    ));

    // A submission on the OTHER form must not fire it.
    $this->postJson(route('forms.public.submit', ['uid' => $other->uid]), [
        'email' => 'sidebar@example.test',
    ])->assertOk();

    expect(formRunCount($automation))->toBe(0);

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'footer@example.test',
    ])->assertOk();

    expect(formRunCount($automation))->toBe(1);
});

it('keeps the WP-21 any-form behaviour when no form is named', function (): void {
    $automation = Fx::automation($this->customer, formTriggerFlow(['list_id' => $this->list->id], 'any-form'));

    // The §2.2.11 embed path (a form-sourced subscriber with no §2.7 form).
    Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'legacy@example.test',
        'source' => SubscriberSource::Form,
    ]);

    expect(formRunCount($automation))->toBe(1);

    // …and a §2.7 submission fires it too.
    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'modern@example.test',
    ])->assertOk();

    expect(formRunCount($automation))->toBe(2);
});

it('never fires a form trigger across tenants', function (): void {
    $other = Customer::factory()->create();

    [$foreignAutomation, $foreignForm] = app(CurrentCustomer::class)->runAs($other, function () use ($other): array {
        $list = EmailList::factory()->create(['customer_id' => $other->id]);

        return [
            Fx::automation($other, formTriggerFlow([], 'foreign-form')),
            Form::factory()->create(['customer_id' => $other->id, 'list_id' => $list->id]),
        ];
    });

    $mine = Fx::automation($this->customer, formTriggerFlow([], 'mine'));

    // Their form's submission enrols only their automation…
    $this->postJson(route('forms.public.submit', ['uid' => $foreignForm->uid]), [
        'email' => 'theirs@example.test',
    ])->assertOk();

    expect(formRunCount($foreignAutomation))->toBe(1)
        ->and(formRunCount($mine))->toBe(0);

    // …and mine enrols only mine.
    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'ours@example.test',
    ])->assertOk();

    expect(formRunCount($mine))->toBe(1)
        ->and(formRunCount($foreignAutomation))->toBe(1);
});

it('restores the event bus after a muted block throws', function (): void {
    expect(AutomationEventDispatcher::isMuted(AutomationEvents::FORM_SUBMITTED))->toBeFalse();

    try {
        AutomationEventDispatcher::mute(AutomationEvents::FORM_SUBMITTED, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(AutomationEventDispatcher::isMuted(AutomationEvents::FORM_SUBMITTED))->toBeFalse();
});
