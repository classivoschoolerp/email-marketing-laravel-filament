<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\SurveyResponderStatus;
use App\Enums\SurveyStatus;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\FieldOption;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Models\Survey;
use App\Models\SurveyField;
use App\Models\SurveyResponder;
use App\Models\SurveyResponderAnswer;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
 * §1.9 survey public pages, driving the §2.8 module.
 *
 * F-1.9-001 render, multi-step, progress bar
 * F-1.9-002 response saved & linked to the subscriber when identified
 * F-1.9-003 thank-you page / redirect
 * F-1.9-004 close after date or response limit
 * F-2.8-009 anonymous vs identified responses
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-13 09:00:00');

    // Public requests: nobody is logged in.
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'display_name' => 'Product survey 調査 ✨',
        'fields_per_step' => 1,
    ]);

    $this->q1 = SurveyField::factory()->create([
        'survey_id' => $this->survey->id,
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::Radio,
        'tag' => 'HAPPY',
        'label' => 'How happy are you?',
        'required' => true,
        'sort_order' => 0,
    ]);

    foreach ([['Very', 'very'], ['Not really', 'no']] as $index => [$label, $value]) {
        FieldOption::withoutTenancy()->create([
            'customer_id' => $this->customer->id,
            'owner_type' => SurveyField::class,
            'owner_id' => $this->q1->id,
            'label' => $label,
            'value' => $value,
            'sort_order' => $index,
        ]);
    }

    $this->q2 = SurveyField::factory()->create([
        'survey_id' => $this->survey->id,
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::Textarea,
        'tag' => 'WHY',
        'label' => 'Why?',
        'sort_order' => 1,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/** Walk from the start page to a step-1 URL, returning the responder. */
function beginSurvey(Survey $survey, ?Subscriber $subscriber = null): SurveyResponder
{
    $payload = $subscriber === null ? [] : ['s' => $subscriber->uid];

    test()->post(route('surveys.public.begin', ['uid' => $survey->uid]), $payload)
        ->assertRedirect();

    return SurveyResponder::withoutTenancy()->where('survey_id', $survey->id)->latest('id')->firstOrFail();
}

it('renders the start page with the public name and description (F-2.8-004)', function (): void {
    $this->survey->forceFill(['start_page_content' => '<p>Takes 30 seconds.</p>'])->save();

    $this->get(route('surveys.public.start', ['uid' => $this->survey->uid]))
        ->assertOk()
        ->assertSee('Product survey 調査 ✨', false)
        ->assertSee('Takes 30 seconds.', false)
        ->assertSee(route('surveys.public.begin', ['uid' => $this->survey->uid]), false);
});

it('walks the multi-step wizard with a progress bar (F-1.9-001)', function (): void {
    $responder = beginSurvey($this->survey);

    $step1 = route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]);

    $this->get($step1)
        ->assertOk()
        ->assertSee('How happy are you?')
        ->assertSee('Step 1 of 2')
        ->assertSee('role="progressbar"', false)
        ->assertSee('width: 50%', false)
        // Only this step's question is on the page.
        ->assertDontSee('Why?');

    $this->post($step1, ['HAPPY' => 'very'])->assertRedirect(route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 2,
    ]));

    $step2 = route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 2,
    ]);

    $this->get($step2)->assertOk()->assertSee('Why?')->assertSee('Step 2 of 2');

    $this->post($step2, ['WHY' => 'Because 良い 👍'])
        ->assertRedirect(route('surveys.public.thanks', [
            'uid' => $this->survey->uid, 'responder' => $responder->uid,
        ]));

    $responder->refresh();

    expect($responder->status)->toBe(SurveyResponderStatus::Complete)
        ->and($responder->completed_at)->not->toBeNull();

    // Answers stored, CJK + emoji intact.
    $answers = SurveyResponderAnswer::withoutTenancy()
        ->where('survey_responder_id', $responder->id)
        ->pluck('value', 'survey_field_id');

    expect($answers[$this->q1->id])->toBe('very')
        ->and($answers[$this->q2->id])->toBe('Because 良い 👍');
});

it('validates each step against the shared field library (F-1.9-001)', function (): void {
    $responder = beginSurvey($this->survey);

    $step1 = route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]);

    // Required, and only the configured options are accepted.
    $this->post($step1, [])->assertSessionHasErrors('HAPPY');
    $this->post($step1, ['HAPPY' => 'maybe'])->assertSessionHasErrors('HAPPY');

    expect(SurveyResponderAnswer::withoutTenancy()->count())->toBe(0);

    $this->post($step1, ['HAPPY' => 'no'])->assertRedirect();
    expect(SurveyResponderAnswer::withoutTenancy()->count())->toBe(1);
});

it('links the response to the subscriber when identified (F-1.9-002 / F-2.8-009)', function (): void {
    $subscriber = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'respondent@example.test',
    ]);

    $this->get(route('surveys.public.start', ['uid' => $this->survey->uid, 's' => $subscriber->uid]))
        ->assertOk()
        ->assertSee('respondent@example.test');

    $responder = beginSurvey($this->survey, $subscriber);

    expect($responder->subscriber_id)->toBe($subscriber->id)
        ->and($responder->email)->toBe('respondent@example.test')
        ->and($responder->isAnonymous())->toBeFalse();

    // …and an anonymous walk stays anonymous.
    $anonymous = beginSurvey($this->survey);

    expect($anonymous->subscriber_id)->toBeNull()
        ->and($anonymous->isAnonymous())->toBeTrue()
        ->and($anonymous->id)->not->toBe($responder->id);
});

it('copies mapped answers onto the subscriber on completion (F-1.9-002)', function (): void {
    $listField = ListField::factory()->for($this->list, 'list')->create([
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::Radio,
        'tag' => 'HAPPINESS',
        'label' => 'Happiness',
    ]);

    $this->q1->forceFill(['list_field_id' => $listField->id])->save();

    $subscriber = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'mapped@example.test',
    ]);

    $responder = beginSurvey($this->survey, $subscriber);

    $this->post(route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]), ['HAPPY' => 'very'])->assertRedirect();

    // Not yet: the write-back happens on completion, not per step.
    expect(SubscriberFieldValue::withoutTenancy()->where('subscriber_id', $subscriber->id)->count())->toBe(0);

    $this->post(route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 2,
    ]), ['WHY' => 'good'])->assertRedirect();

    expect(SubscriberFieldValue::withoutTenancy()
        ->where('subscriber_id', $subscriber->id)
        ->where('list_field_id', $listField->id)
        ->value('value'))->toBe('very');
});

it('shows the thank-you page and honours a redirect (F-1.9-003)', function (): void {
    $this->survey->forceFill([
        'fields_per_step' => 0,
        'thank_you_content' => '<p>Merci beaucoup 🙏</p>',
    ])->save();

    $responder = beginSurvey($this->survey->refresh());

    $this->post(route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]), ['HAPPY' => 'very', 'WHY' => 'fine'])
        ->assertRedirect(route('surveys.public.thanks', [
            'uid' => $this->survey->uid, 'responder' => $responder->uid,
        ]));

    $this->get(route('surveys.public.thanks', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid,
    ]))->assertOk()->assertSee('Merci beaucoup 🙏', false);

    // A configured redirect wins over the thank-you page.
    $this->survey->forceFill(['redirect_url' => 'https://example.com/done'])->save();

    $second = beginSurvey($this->survey->refresh());

    $this->post(route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $second->uid, 'step' => 1,
    ]), ['HAPPY' => 'no', 'WHY' => 'meh'])->assertRedirect('https://example.com/done');
});

it('closes the survey on its end date and its response limit (F-1.9-004)', function (): void {
    $this->survey->forceFill(['end_at' => now()->addHour()])->save();

    $this->get(route('surveys.public.start', ['uid' => $this->survey->uid]))
        ->assertOk()
        ->assertSee(__('surveys.public.start_button'));

    Carbon::setTestNow('2026-08-13 11:00:00');

    $this->get(route('surveys.public.start', ['uid' => $this->survey->uid]))
        ->assertOk()
        ->assertSee(__('surveys.public.closed.ended'))
        ->assertDontSee(__('surveys.public.start_button'));

    // Starting a response is refused too, not just the page.
    $this->post(route('surveys.public.begin', ['uid' => $this->survey->uid]))
        ->assertOk()
        ->assertSee(__('surveys.public.closed.ended'));

    expect(SurveyResponder::withoutTenancy()->count())->toBe(0);

    // Response limit.
    $capped = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'max_responders' => 1,
    ]);
    SurveyField::factory()->create([
        'survey_id' => $capped->id, 'customer_id' => $this->customer->id, 'tag' => 'ONE', 'sort_order' => 0,
    ]);
    SurveyResponder::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'survey_id' => $capped->id,
        'status' => SurveyResponderStatus::Complete,
    ]);

    $this->get(route('surveys.public.start', ['uid' => $capped->uid]))
        ->assertOk()
        ->assertSee(__('surveys.public.closed.full'));

    // A manual close and a draft behave the same way.
    $this->survey->forceFill(['end_at' => null, 'status' => SurveyStatus::Closed])->save();
    $this->get(route('surveys.public.start', ['uid' => $this->survey->uid]))
        ->assertOk()->assertSee(__('surveys.public.closed.closed'));
});

it('lets an already-started response finish after the cap is reached (F-1.9-004)', function (): void {
    $this->survey->forceFill(['max_responders' => 1, 'fields_per_step' => 0])->save();

    $responder = beginSurvey($this->survey->refresh());

    // Someone else completes and fills the cap while this one is mid-wizard.
    SurveyResponder::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'survey_id' => $this->survey->id,
        'status' => SurveyResponderStatus::Complete,
    ]);

    expect($this->survey->refresh()->isOpen())->toBeFalse();

    // The work already done is not thrown away.
    $this->get(route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]))->assertOk();

    $this->post(route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]), ['HAPPY' => 'very'])->assertRedirect();

    expect($responder->refresh()->status)->toBe(SurveyResponderStatus::Complete);

    // But a NEW response is refused.
    $this->post(route('surveys.public.begin', ['uid' => $this->survey->uid]))
        ->assertOk()->assertSee(__('surveys.public.closed.full'));
});

it('enforces the identified-only and one-response-per-subscriber rules (F-2.8-009)', function (): void {
    $this->survey->forceFill(['allow_anonymous' => false, 'fields_per_step' => 0])->save();

    $this->get(route('surveys.public.start', ['uid' => $this->survey->uid]))
        ->assertOk()->assertSee(__('surveys.public.closed.identified_only'));

    $this->post(route('surveys.public.begin', ['uid' => $this->survey->uid]))
        ->assertOk()->assertSee(__('surveys.public.closed.identified_only'));

    expect(SurveyResponder::withoutTenancy()->count())->toBe(0);

    $subscriber = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'only@example.test',
    ]);

    $responder = beginSurvey($this->survey->refresh(), $subscriber);

    $this->post(route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]), ['HAPPY' => 'very', 'WHY' => 'x'])->assertRedirect();

    // A second attempt by the same subscriber is refused.
    $this->get(route('surveys.public.start', ['uid' => $this->survey->uid, 's' => $subscriber->uid]))
        ->assertOk()->assertSee(__('surveys.public.closed.already_responded'));
});

it('resumes an unfinished identified response instead of forking a second one', function (): void {
    $subscriber = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'resume@example.test',
    ]);

    $first = beginSurvey($this->survey, $subscriber);
    $second = beginSurvey($this->survey, $subscriber);

    expect($second->id)->toBe($first->id)
        ->and(SurveyResponder::withoutTenancy()->where('survey_id', $this->survey->id)->count())->toBe(1);
});

it('treats a foreign or unknown subscriber uid as anonymous, never as a leak (F-2.8-009)', function (): void {
    $other = Customer::factory()->create();

    $foreignSubscriber = app(CurrentCustomer::class)->runAs($other, function () use ($other): Subscriber {
        $list = EmailList::factory()->create(['customer_id' => $other->id]);

        return Subscriber::factory()->create([
            'customer_id' => $other->id,
            'list_id' => $list->id,
            'email' => 'foreigner@example.test',
        ]);
    });

    $this->get(route('surveys.public.start', ['uid' => $this->survey->uid, 's' => $foreignSubscriber->uid]))
        ->assertOk()
        ->assertDontSee('foreigner@example.test');

    $responder = beginSurvey($this->survey, $foreignSubscriber);

    expect($responder->subscriber_id)->toBeNull();

    // An entirely unknown uid behaves identically.
    $this->get(route('surveys.public.start', ['uid' => $this->survey->uid, 's' => (string) Str::uuid()]))
        ->assertOk();
});

it('refuses a responder uid that belongs to another survey or tenant', function (): void {
    $responder = beginSurvey($this->survey);

    $otherSurvey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);
    SurveyField::factory()->create([
        'survey_id' => $otherSurvey->id, 'customer_id' => $this->customer->id, 'tag' => 'X', 'sort_order' => 0,
    ]);

    $this->get(route('surveys.public.step', [
        'uid' => $otherSurvey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]))->assertNotFound();

    $this->get(route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => (string) Str::uuid(), 'step' => 1,
    ]))->assertNotFound();

    $this->get(route('surveys.public.start', ['uid' => (string) Str::uuid()]))->assertNotFound();
});

it('drops honeypot traffic on the public survey endpoints', function (): void {
    $this->post(route('surveys.public.begin', ['uid' => $this->survey->uid]), [
        PublicSubscribeController::HONEYPOT_FIELD => 'spam',
    ])->assertOk();

    expect(SurveyResponder::withoutTenancy()->count())->toBe(0);
});

it('sends a completed responder back to the thank-you page, never round the wizard again', function (): void {
    $this->survey->forceFill(['fields_per_step' => 0])->save();

    $responder = beginSurvey($this->survey->refresh());

    $this->post(route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]), ['HAPPY' => 'very', 'WHY' => 'ok'])->assertRedirect();

    $completedAt = $responder->refresh()->completed_at;

    $this->get(route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]))->assertRedirect(route('surveys.public.thanks', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid,
    ]));

    // Re-posting must not double-count against the cap.
    $this->post(route('surveys.public.step', [
        'uid' => $this->survey->uid, 'responder' => $responder->uid, 'step' => 1,
    ]), ['HAPPY' => 'no'])->assertRedirect();

    expect($responder->refresh()->completed_at->equalTo($completedAt))->toBeTrue()
        ->and($this->survey->refresh()->completedCount())->toBe(1);
});
