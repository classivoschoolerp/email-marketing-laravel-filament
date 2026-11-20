<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Enums\SurveyResponderStatus;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Models\Survey;
use App\Models\SurveyField;
use App\Models\SurveyResponder;
use App\Services\Automations\AutomationEvents;
use App\Services\Automations\Enroller;
use App\Services\Subscribers\SubscriberTagService;
use App\Support\Tenancy\CurrentCustomer;
use Tests\Support\AutomationFixtures as Fx;

/*
 * F-2.4.2-012 "completed a survey" — closed end to end by the §2.8 module.
 *
 * Finishing the public wizard must enrol the respondent in an automation
 * whose trigger is "survey completed", the trigger must be able to name ONE
 * survey, and an ANONYMOUS response must enrol nobody (there is no
 * subscriber to enrol — that is what anonymous means).
 */

beforeEach(function (): void {
    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'name' => 'NPS',
        'fields_per_step' => 0,
    ]);

    SurveyField::factory()->create([
        'survey_id' => $this->survey->id,
        'customer_id' => $this->customer->id,
        'tag' => 'ANSWER',
        'label' => 'Your answer',
        'sort_order' => 0,
    ]);

    $this->subscriber = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'respondent@example.test',
    ]);
});

function surveyTriggerFlow(array $config, string $tag): array
{
    return [
        Fx::node('t1', AutomationNodeType::TriggerSurveyCompleted, $config, ['next' => 'a1']),
        Fx::node('a1', AutomationNodeType::ActionAddTag, ['tag' => $tag], ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ];
}

function surveyRunCount(Automation $automation): int
{
    return AutomationRun::withoutTenancy()->where('automation_id', $automation->id)->count();
}

/** Complete the public wizard for $survey as $subscriber (or anonymously). */
function completePublicSurvey(Survey $survey, ?Subscriber $subscriber, string $answer = 'great'): SurveyResponder
{
    $payload = $subscriber === null ? [] : ['s' => $subscriber->uid];

    test()->post(route('surveys.public.begin', ['uid' => $survey->uid]), $payload)->assertRedirect();

    $responder = SurveyResponder::withoutTenancy()
        ->where('survey_id', $survey->id)->latest('id')->firstOrFail();

    test()->post(route('surveys.public.step', [
        'uid' => $survey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]), ['ANSWER' => $answer])->assertRedirect();

    return $responder->refresh();
}

it('enrols and runs the automation when the public wizard completes (F-2.4.2-012)', function (): void {
    $automation = Fx::automation($this->customer, surveyTriggerFlow(
        ['survey_id' => $this->survey->id],
        'survey-done',
    ));

    $responder = completePublicSurvey($this->survey, $this->subscriber);

    expect($responder->status)->toBe(SurveyResponderStatus::Complete)
        ->and(surveyRunCount($automation))->toBe(1)
        ->and(app(SubscriberTagService::class)->has($this->subscriber->refresh(), 'survey-done'))->toBeTrue();
});

it('does not fire while the response is still unfinished', function (): void {
    $survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'fields_per_step' => 1,
    ]);

    foreach (['Q1', 'Q2'] as $index => $tag) {
        SurveyField::factory()->create([
            'survey_id' => $survey->id,
            'customer_id' => $this->customer->id,
            'tag' => $tag,
            'sort_order' => $index,
        ]);
    }

    $automation = Fx::automation($this->customer, surveyTriggerFlow([], 'finished'));

    $this->post(route('surveys.public.begin', ['uid' => $survey->uid]), ['s' => $this->subscriber->uid])
        ->assertRedirect();

    $responder = SurveyResponder::withoutTenancy()->where('survey_id', $survey->id)->firstOrFail();

    $this->post(route('surveys.public.step', [
        'uid' => $survey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]), ['Q1' => 'a'])->assertRedirect();

    expect(surveyRunCount($automation))->toBe(0);

    $this->post(route('surveys.public.step', [
        'uid' => $survey->uid, 'responder' => $responder->uid, 'step' => 2,
    ]), ['Q2' => 'b'])->assertRedirect();

    expect(surveyRunCount($automation))->toBe(1);
});

it('filters the trigger to one specific survey (F-2.4.2-012)', function (): void {
    $other = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'name' => 'CSAT',
        'fields_per_step' => 0,
    ]);
    SurveyField::factory()->create([
        'survey_id' => $other->id, 'customer_id' => $this->customer->id, 'tag' => 'ANSWER', 'sort_order' => 0,
    ]);

    $automation = Fx::automation($this->customer, surveyTriggerFlow(
        ['survey_id' => $this->survey->id],
        'nps-only',
    ));

    completePublicSurvey($other, $this->subscriber);
    expect(surveyRunCount($automation))->toBe(0);

    completePublicSurvey($this->survey, $this->subscriber);
    expect(surveyRunCount($automation))->toBe(1);
});

it('enrols nobody for an anonymous response (F-2.8-009)', function (): void {
    $automation = Fx::automation($this->customer, surveyTriggerFlow([], 'anon'));

    $responder = completePublicSurvey($this->survey, null);

    expect($responder->isAnonymous())->toBeTrue()
        ->and($responder->status)->toBe(SurveyResponderStatus::Complete)
        ->and(surveyRunCount($automation))->toBe(0);
});

it('wakes a run parked on a survey_completed wait node', function (): void {
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

    app(Enroller::class)->enter($waiting, $this->subscriber);

    expect(app(SubscriberTagService::class)->has($this->subscriber, 'answered'))->toBeFalse();

    completePublicSurvey($this->survey, $this->subscriber);

    expect(app(SubscriberTagService::class)->has($this->subscriber->refresh(), 'answered'))->toBeTrue();
});

it('never fires a survey trigger across tenants', function (): void {
    $other = Customer::factory()->create();

    [$foreignAutomation, $foreignSurvey, $foreignSubscriber] = app(CurrentCustomer::class)
        ->runAs($other, function () use ($other): array {
            $list = EmailList::factory()->create(['customer_id' => $other->id]);

            $survey = Survey::factory()->create([
                'customer_id' => $other->id, 'list_id' => $list->id, 'fields_per_step' => 0,
            ]);
            SurveyField::factory()->create([
                'survey_id' => $survey->id, 'customer_id' => $other->id, 'tag' => 'ANSWER', 'sort_order' => 0,
            ]);

            return [
                Fx::automation($other, surveyTriggerFlow([], 'foreign')),
                $survey,
                Subscriber::factory()->create([
                    'customer_id' => $other->id, 'list_id' => $list->id, 'email' => 'foreigner@example.test',
                ]),
            ];
        });

    $mine = Fx::automation($this->customer, surveyTriggerFlow([], 'mine'));

    completePublicSurvey($foreignSurvey, $foreignSubscriber);

    expect(surveyRunCount($foreignAutomation))->toBe(1)
        ->and(surveyRunCount($mine))->toBe(0);

    completePublicSurvey($this->survey, $this->subscriber);

    expect(surveyRunCount($mine))->toBe(1)
        ->and(surveyRunCount($foreignAutomation))->toBe(1);
});
