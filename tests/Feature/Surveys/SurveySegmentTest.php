<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Enums\SurveyResponderStatus;
use App\Jobs\Surveys\BackfillSurveyAnswersJob;
use App\Models\EmailList;
use App\Models\FieldOption;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Models\Survey;
use App\Models\SurveyField;
use App\Models\SurveyResponder;
use App\Models\SurveyResponderAnswer;
use App\Services\Lists\FieldValueService;
use App\Services\Segments\SegmentQueryBuilder;
use App\Services\Surveys\SurveyAnswerSegmentBuilder;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AutomationFixtures as Fx;

/*
 * F-2.8-007 — create a list segment from survey answers.
 *
 * Segments are dynamic (F-2.2.10-012), so the answer has to become a
 * property of the SUBSCRIBER for a segment to be able to select on it. The
 * builder makes that mapping, backfills past identified answers and then
 * produces an ordinary field-condition segment.
 */

beforeEach(function (): void {
    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'name' => 'NPS',
    ]);

    $this->question = SurveyField::factory()->create([
        'survey_id' => $this->survey->id,
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::Radio,
        'tag' => 'PROMOTER',
        'label' => 'Would you recommend us?',
        'sort_order' => 0,
    ]);

    foreach ([['Yes', 'yes'], ['No', 'no']] as $index => [$label, $value]) {
        FieldOption::withoutTenancy()->create([
            'customer_id' => $this->customer->id,
            'owner_type' => SurveyField::class,
            'owner_id' => $this->question->id,
            'label' => $label, 'value' => $value, 'sort_order' => $index,
        ]);
    }

    $this->builder = app(SurveyAnswerSegmentBuilder::class);
});

/** A completed, identified response with one answer. */
function respondAs(Survey $survey, SurveyField $field, Subscriber $subscriber, string $value): SurveyResponder
{
    /** @var SurveyResponder $responder */
    $responder = SurveyResponder::withoutTenancy()->create([
        'customer_id' => $survey->customer_id,
        'survey_id' => $survey->id,
        'subscriber_id' => $subscriber->id,
        'email' => $subscriber->email,
        'status' => SurveyResponderStatus::Complete,
        'completed_at' => now(),
    ]);

    SurveyResponderAnswer::withoutTenancy()->create([
        'customer_id' => $survey->customer_id,
        'survey_responder_id' => $responder->id,
        'survey_field_id' => $field->id,
        'value' => app(FieldValueService::class)->toStorage($field, $value),
    ]);

    return $responder;
}

it('creates a working dynamic segment from an answer (F-2.8-007)', function (): void {
    $promoter = Subscriber::factory()->create([
        'customer_id' => $this->customer->id, 'list_id' => $this->list->id, 'email' => 'yes@example.test',
    ]);
    $detractor = Subscriber::factory()->create([
        'customer_id' => $this->customer->id, 'list_id' => $this->list->id, 'email' => 'no@example.test',
    ]);

    respondAs($this->survey, $this->question, $promoter, 'yes');
    respondAs($this->survey, $this->question, $detractor, 'no');

    $segment = $this->builder->build($this->survey, $this->question, SegmentOperator::Is, 'yes');

    expect($segment)->toBeInstanceOf(ListSegment::class)
        ->and((int) $segment->list_id)->toBe((int) $this->list->id)
        ->and($segment->name)->toBe('NPS — Would you recommend us?');

    $condition = $segment->conditions()->firstOrFail();

    expect($condition->type)->toBe(SegmentConditionType::Field)
        ->and($condition->operator)->toBe(SegmentOperator::Is)
        ->and($condition->value)->toBe('yes');

    // Membership is evaluated live by the existing engine.
    $members = app(SegmentQueryBuilder::class)->subscribers($segment)->pluck('email')->all();

    expect($members)->toBe(['yes@example.test']);
});

it('creates the list field mapping when the question has none (F-2.8-007)', function (): void {
    expect($this->question->list_field_id)->toBeNull();

    $this->builder->ensureMapping($this->survey, $this->question);

    $this->question->refresh();
    expect($this->question->list_field_id)->not->toBeNull();

    $listField = ListField::withoutTenancy()->findOrFail($this->question->list_field_id);

    expect($listField->list_id)->toBe($this->list->id)
        ->and($listField->tag)->toBe('PROMOTER')
        ->and($listField->type)->toBe(ListFieldType::Radio)
        // Survey-derived fields are answer storage, not signup inputs.
        ->and($listField->visible_on_form)->toBeFalse()
        // Choice options are copied so the segment value picker works.
        ->and($listField->options()->pluck('value')->all())->toBe(['yes', 'no']);
});

it('reuses an existing mapping instead of creating a duplicate field', function (): void {
    $existing = ListField::factory()->for($this->list, 'list')->create([
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::Radio,
        'tag' => 'NPSANSWER',
        'label' => 'NPS answer',
    ]);

    $this->question->forceFill(['list_field_id' => $existing->id])->save();

    $before = ListField::withoutTenancy()->count();
    $mapped = $this->builder->ensureMapping($this->survey, $this->question->refresh());

    expect($mapped->id)->toBe($existing->id)
        ->and(ListField::withoutTenancy()->count())->toBe($before);
});

it('never collides with a reserved or already-used merge tag (F-2.2.9-006)', function (): void {
    // A question whose tag is a reserved schema tag.
    $reserved = SurveyField::factory()->create([
        'survey_id' => $this->survey->id,
        'customer_id' => $this->customer->id,
        'tag' => 'EMAIL',
        'label' => 'Your email',
        'sort_order' => 1,
    ]);

    $field = $this->builder->ensureMapping($this->survey, $reserved);

    expect($field->tag)->not->toBe('EMAIL')
        ->and(ListField::isReservedTag($field->tag))->toBeFalse();

    // A question whose tag is already taken on the list.
    ListField::factory()->for($this->list, 'list')->create([
        'customer_id' => $this->customer->id, 'tag' => 'PROMOTER', 'label' => 'Existing',
    ]);

    $second = $this->builder->ensureMapping($this->survey, $this->question);

    expect($second->tag)->not->toBe('PROMOTER');
});

it('queues the backfill so old answers become segmentable (F-2.8-007)', function (): void {
    Queue::fake();

    $this->builder->ensureMapping($this->survey, $this->question);

    Queue::assertPushed(BackfillSurveyAnswersJob::class);
});

it('backfills only completed, identified answers of the right list', function (): void {
    $identified = Subscriber::factory()->create([
        'customer_id' => $this->customer->id, 'list_id' => $this->list->id, 'email' => 'done@example.test',
    ]);

    respondAs($this->survey, $this->question, $identified, 'yes');

    // Anonymous response — nothing to write onto.
    SurveyResponderAnswer::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'survey_responder_id' => SurveyResponder::withoutTenancy()->create([
            'customer_id' => $this->customer->id,
            'survey_id' => $this->survey->id,
            'status' => SurveyResponderStatus::Complete,
        ])->id,
        'survey_field_id' => $this->question->id,
        'value' => 'no',
    ]);

    // Half-finished identified response — not an answer yet.
    $unfinished = Subscriber::factory()->create([
        'customer_id' => $this->customer->id, 'list_id' => $this->list->id, 'email' => 'partial@example.test',
    ]);

    SurveyResponderAnswer::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'survey_responder_id' => SurveyResponder::withoutTenancy()->create([
            'customer_id' => $this->customer->id,
            'survey_id' => $this->survey->id,
            'subscriber_id' => $unfinished->id,
            'status' => SurveyResponderStatus::Incomplete,
        ])->id,
        'survey_field_id' => $this->question->id,
        'value' => 'no',
    ]);

    $segment = $this->builder->build($this->survey, $this->question, SegmentOperator::Is, 'yes');

    expect(app(SegmentQueryBuilder::class)->subscribers($segment)->pluck('email')->all())
        ->toBe(['done@example.test']);

    $noSegment = $this->builder->build($this->survey, $this->question->refresh(), SegmentOperator::Is, 'no');

    expect(app(SegmentQueryBuilder::class)->subscribers($noSegment)->count())->toBe(0);
});

it('refuses to build a segment for a survey with no list', function (): void {
    $listless = Survey::factory()->create(['customer_id' => $this->customer->id, 'list_id' => null]);

    $question = SurveyField::factory()->create([
        'survey_id' => $listless->id,
        'customer_id' => $this->customer->id,
        'tag' => 'ANY',
        'sort_order' => 0,
    ]);

    expect(fn () => $this->builder->build($listless, $question, SegmentOperator::Is, 'x'))
        ->toThrow(RuntimeException::class);
});
