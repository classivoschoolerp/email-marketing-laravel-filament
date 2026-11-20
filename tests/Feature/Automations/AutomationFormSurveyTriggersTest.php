<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Enums\SubscriberSource;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Automations\AutomationEvents;
use App\Services\Automations\Enroller;
use App\Services\Subscribers\SubscriberTagService;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\AutomationFixtures as Fx;

/*
 * F-2.4.2-011 "Submitted a form / landing page" and F-2.4.2-012 "Completed a
 * survey".
 *
 * Neither native producer exists in the product: the forms + landing-page
 * builder is spec §2.7 (P2) and the survey module is §2.8 (P2). Both rows
 * therefore stay IN-PROGRESS. What is proven here is the seam that carries
 * those two events today, end to end:
 *
 *  - form_submitted    fires from the form-sourced subscriber path
 *                      (AutomationHooks: Subscriber::created with
 *                      source = form) — the WP-08 embed/hosted forms;
 *  - form_submitted +  can be raised by an external form/survey tool through
 *    survey_completed  the per-automation inbound hook (F-2.4.2-014), which
 *                      whitelists exactly these two canonical names and
 *                      namespaces everything else under `custom:`.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-09 09:00:00');
    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
});

afterEach(fn () => Carbon::setTestNow());

/** trigger → add tag → exit, so a fired trigger leaves a visible side effect. */
function taggingFlow(AutomationNodeType $trigger, array $config, string $tag): array
{
    return [
        Fx::node('t1', $trigger, $config, ['next' => 'a1']),
        Fx::node('a1', AutomationNodeType::ActionAddTag, ['tag' => $tag], ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ];
}

function runCount(Automation $automation): int
{
    return AutomationRun::withoutTenancy()->where('automation_id', $automation->id)->count();
}

/** A published+active webhook automation with a known token. */
function hookAutomation(Customer $customer, EmailList $list, string $token): Automation
{
    return Fx::automation($customer, [
        Fx::node('h1', AutomationNodeType::TriggerWebhook, ['list_id' => $list->id, 'token' => $token], ['next' => 'hx']),
        Fx::node('hx', AutomationNodeType::LogicExit, [], []),
    ]);
}

it('runs a form-sourced subscription all the way through the form trigger (F-2.4.2-011)', function (): void {
    $automation = Fx::automation($this->customer, taggingFlow(
        AutomationNodeType::TriggerFormSubmitted,
        ['list_id' => $this->list->id],
        'from-form',
    ));

    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'email' => 'signup@example.test',
        'source' => SubscriberSource::Form,
    ]);

    expect(runCount($automation))->toBe(1)
        ->and(app(SubscriberTagService::class)->has($subscriber->refresh(), 'from-form'))->toBeTrue();
});

it('does not fire the form trigger for non-form sources or other lists (F-2.4.2-011)', function (): void {
    $automation = Fx::automation($this->customer, taggingFlow(
        AutomationNodeType::TriggerFormSubmitted,
        ['list_id' => $this->list->id],
        'from-form',
    ));

    // Imported / API subscribers are not form submissions.
    Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'email' => 'imported@example.test',
        'source' => SubscriberSource::Import,
    ]);

    expect(runCount($automation))->toBe(0);

    // A form signup on a different list is filtered out by the trigger config.
    $otherList = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    Subscriber::factory()->create([
        'list_id' => $otherList->id,
        'customer_id' => $this->customer->id,
        'email' => 'other-list@example.test',
        'source' => SubscriberSource::Form,
    ]);

    expect(runCount($automation))->toBe(0);
});

it('raises survey_completed from the inbound hook and runs the survey trigger (F-2.4.2-012)', function (): void {
    $token = Str::random(32);
    $hook = hookAutomation($this->customer, $this->list, $token);

    $survey = Fx::automation($this->customer, taggingFlow(
        AutomationNodeType::TriggerSurveyCompleted,
        [],
        'survey-done',
    ));

    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'email' => 'respondent@example.test',
    ]);

    $this->postJson(route('automations.hook', ['uid' => $hook->uid, 'token' => $token]), [
        'email' => $subscriber->email,
        'event' => 'survey_completed',
        'score' => 9,
    ])->assertOk()->assertJson([
        'ok' => true,
        'entered' => true,
        'event' => AutomationEvents::SURVEY_COMPLETED,
    ]);

    expect(runCount($survey))->toBe(1)
        ->and(app(SubscriberTagService::class)->has($subscriber->refresh(), 'survey-done'))->toBeTrue()
        ->and(runCount($hook))->toBe(1);
});

it('raises form_submitted from the inbound hook for external form tools (F-2.4.2-011)', function (): void {
    $token = Str::random(32);
    $hook = hookAutomation($this->customer, $this->list, $token);

    $form = Fx::automation($this->customer, taggingFlow(
        AutomationNodeType::TriggerFormSubmitted,
        ['list_id' => $this->list->id],
        'external-form',
    ));

    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'email' => 'lead@example.test',
        'source' => SubscriberSource::Api,
    ]);

    // Creating an API-sourced subscriber must not have fired anything.
    expect(runCount($form))->toBe(0);

    $this->postJson(route('automations.hook', ['uid' => $hook->uid, 'token' => $token]), [
        'email' => $subscriber->email,
        'event' => 'FORM_SUBMITTED',
    ])->assertOk()->assertJson(['event' => AutomationEvents::FORM_SUBMITTED]);

    expect(runCount($form))->toBe(1)
        ->and(app(SubscriberTagService::class)->has($subscriber->refresh(), 'external-form'))->toBeTrue();
});

it('wakes a run parked on a survey wait node when the hook raises it (F-2.4.2-012)', function (): void {
    $token = Str::random(32);
    $hook = hookAutomation($this->customer, $this->list, $token);

    $waiting = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'w1']),
        Fx::node('w1', AutomationNodeType::LogicWaitEvent, [
            'event' => AutomationEvents::SURVEY_COMPLETED,
            'timeout_value' => 30,
            'timeout_unit' => 'days',
        ], ['event' => 'a1', 'timeout' => 'x1']),
        Fx::node('a1', AutomationNodeType::ActionAddTag, ['tag' => 'answered'], ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ]);

    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'email' => 'parked@example.test',
    ]);

    app(Enroller::class)->enter($waiting, $subscriber);

    $run = AutomationRun::withoutTenancy()->where('automation_id', $waiting->id)->firstOrFail();
    expect($run->waiting_for)->toBe(AutomationEvents::SURVEY_COMPLETED)
        ->and(app(SubscriberTagService::class)->has($subscriber, 'answered'))->toBeFalse();

    $this->postJson(route('automations.hook', ['uid' => $hook->uid, 'token' => $token]), [
        'email' => $subscriber->email,
        'event' => 'survey_completed',
    ])->assertOk();

    expect(app(SubscriberTagService::class)->has($subscriber->refresh(), 'answered'))->toBeTrue()
        ->and(AutomationRun::withoutTenancy()->whereKey($run->id)->value('waiting_for'))->toBeNull();
});

it('namespaces every other inbound event name so it cannot forge a trigger (F-2.4.2-011/012)', function (): void {
    $token = Str::random(32);
    $hook = hookAutomation($this->customer, $this->list, $token);

    $survey = Fx::automation($this->customer, taggingFlow(AutomationNodeType::TriggerSurveyCompleted, [], 'survey-done'));
    $form = Fx::automation($this->customer, taggingFlow(AutomationNodeType::TriggerFormSubmitted, ['list_id' => $this->list->id], 'from-form'));
    $unsubscribed = Fx::automation($this->customer, taggingFlow(AutomationNodeType::TriggerUnsubscribed, [], 'gone'));

    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'email' => 'noise@example.test',
    ]);

    foreach (['nps-2026', 'unsubscribed', 'subscribed'] as $name) {
        $this->postJson(route('automations.hook', ['uid' => $hook->uid, 'token' => $token]), [
            'email' => $subscriber->email,
            'event' => $name,
        ])->assertOk()->assertJson(['event' => AutomationEvents::CUSTOM_PREFIX.$name]);
    }

    expect(runCount($survey))->toBe(0)
        ->and(runCount($form))->toBe(0)
        ->and(runCount($unsubscribed))->toBe(0);

    expect(AutomationEvents::normalizeInbound(' Survey_Completed '))->toBe(AutomationEvents::SURVEY_COMPLETED)
        ->and(AutomationEvents::normalizeInbound('form_submitted'))->toBe(AutomationEvents::FORM_SUBMITTED)
        ->and(AutomationEvents::normalizeInbound('tag_added'))->toBe('custom:tag_added')
        ->and(AutomationEvents::INBOUND_ALLOWED)->toBe([
            AutomationEvents::FORM_SUBMITTED,
            AutomationEvents::SURVEY_COMPLETED,
        ]);
});

it('keeps the hook seam inside one tenant (cross-tenant fence)', function (): void {
    $token = Str::random(32);
    $hook = hookAutomation($this->customer, $this->list, $token);

    $otherCustomer = Customer::factory()->create();

    [$foreignSurvey, $foreignSubscriber] = app(CurrentCustomer::class)
        ->runAs($otherCustomer, function () use ($otherCustomer): array {
            [, $subscriber] = Fx::listWithSubscriber($otherCustomer, 'foreigner@example.test');

            return [
                Fx::automation($otherCustomer, taggingFlow(AutomationNodeType::TriggerSurveyCompleted, [], 'survey-done')),
                $subscriber,
            ];
        });

    // My hook cannot name a subscriber that belongs to another tenant.
    $this->postJson(route('automations.hook', ['uid' => $hook->uid, 'token' => $token]), [
        'email' => $foreignSubscriber->email,
        'event' => 'survey_completed',
    ])->assertNotFound()->assertJson(['ok' => false, 'error' => 'subscriber']);

    expect(runCount($foreignSurvey))->toBe(0)
        ->and(runCount($hook))->toBe(0);

    // And a survey event for MY subscriber never reaches the other tenant.
    $mine = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'email' => 'mine@example.test',
    ]);

    $this->postJson(route('automations.hook', ['uid' => $hook->uid, 'token' => $token]), [
        'email' => $mine->email,
        'event' => 'survey_completed',
    ])->assertOk();

    expect(runCount($foreignSurvey))->toBe(0);
});
