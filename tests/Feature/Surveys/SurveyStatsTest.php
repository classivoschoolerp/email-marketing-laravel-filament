<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\SurveyResponderStatus;
use App\Models\EmailList;
use App\Models\FieldOption;
use App\Models\Survey;
use App\Models\SurveyField;
use App\Models\SurveyResponder;
use App\Models\SurveyResponderAnswer;
use App\Services\Lists\FieldValueService;
use App\Services\Surveys\SurveyStats;
use Tests\Support\AutomationFixtures as Fx;

/*
 * F-2.8-006 — per-question aggregate stats behind the pie/bar charts.
 */

beforeEach(function (): void {
    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $this->survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);
    $this->stats = app(SurveyStats::class);
});

function makeQuestion(Survey $survey, ListFieldType $type, string $tag, array $options = [], int $order = 0): SurveyField
{
    $field = SurveyField::factory()->create([
        'survey_id' => $survey->id,
        'customer_id' => $survey->customer_id,
        'type' => $type,
        'tag' => $tag,
        'label' => $tag,
        'sort_order' => $order,
    ]);

    foreach ($options as $index => [$label, $value]) {
        FieldOption::withoutTenancy()->create([
            'customer_id' => $survey->customer_id,
            'owner_type' => SurveyField::class,
            'owner_id' => $field->id,
            'label' => $label, 'value' => $value, 'sort_order' => $index,
        ]);
    }

    return $field->refresh();
}

function answer(Survey $survey, array $answers, SurveyResponderStatus $status = SurveyResponderStatus::Complete): SurveyResponder
{
    /** @var SurveyResponder $responder */
    $responder = SurveyResponder::withoutTenancy()->create([
        'customer_id' => $survey->customer_id,
        'survey_id' => $survey->id,
        'status' => $status,
        'completed_at' => $status === SurveyResponderStatus::Complete ? now() : null,
    ]);

    $values = app(FieldValueService::class);

    foreach ($answers as $fieldId => $value) {
        $field = SurveyField::withoutTenancy()->findOrFail($fieldId);

        SurveyResponderAnswer::withoutTenancy()->create([
            'customer_id' => $survey->customer_id,
            'survey_responder_id' => $responder->id,
            'survey_field_id' => $field->id,
            'value' => $values->toStorage($field, $value),
        ]);
    }

    return $responder;
}

it('aggregates a single-choice question into pie buckets (F-2.8-006)', function (): void {
    $q = makeQuestion($this->survey, ListFieldType::Radio, 'HAPPY', [
        ['Very happy', 'very'], ['Not really', 'no'],
    ]);

    answer($this->survey, [$q->id => 'very']);
    answer($this->survey, [$q->id => 'very']);
    answer($this->survey, [$q->id => 'no']);

    $result = $this->stats->forSurvey($this->survey);
    $question = $result['questions'][0];

    expect($result['completed'])->toBe(3)
        ->and($question['answered'])->toBe(3)
        ->and($question['total_selections'])->toBe(3)
        ->and($question['aggregatable'])->toBeTrue()
        // Sorted best-first, with the OPTION LABEL, not the stored value.
        ->and($question['buckets'][0])->toBe([
            'label' => 'Very happy', 'value' => 'very', 'count' => 2, 'percent' => 66.7,
        ])
        ->and($question['buckets'][1]['label'])->toBe('Not really');
});

it('explodes multi-value answers so the counts mean "times chosen" (F-2.8-006)', function (): void {
    $q = makeQuestion($this->survey, ListFieldType::CheckboxList, 'FEATURES', [
        ['Reports', 'reports'], ['Automations', 'automations'],
    ]);

    answer($this->survey, [$q->id => ['reports', 'automations']]);
    answer($this->survey, [$q->id => ['reports']]);

    $question = $this->stats->forSurvey($this->survey)['questions'][0];

    expect($question['answered'])->toBe(2)
        ->and($question['total_selections'])->toBe(3)
        ->and(collect($question['buckets'])->firstWhere('value', 'reports')['count'])->toBe(2)
        ->and(collect($question['buckets'])->firstWhere('value', 'automations')['count'])->toBe(1);
});

it('averages numeric ratings (F-2.8-006)', function (): void {
    $q = makeQuestion($this->survey, ListFieldType::Rating, 'SCORE');

    foreach ([5, 4, 3] as $score) {
        answer($this->survey, [$q->id => $score]);
    }

    $question = $this->stats->forSurvey($this->survey)['questions'][0];

    expect($question['average'])->toBe(4.0)
        ->and($question['answered'])->toBe(3)
        ->and($question['aggregatable'])->toBeTrue();
});

it('labels boolean answers rather than printing 1 and 0', function (): void {
    $q = makeQuestion($this->survey, ListFieldType::Checkbox, 'AGREE');

    answer($this->survey, [$q->id => true]);
    answer($this->survey, [$q->id => true]);

    $question = $this->stats->forSurvey($this->survey)['questions'][0];

    expect($question['buckets'][0]['label'])->toBe(__('surveys.stats.yes'))
        ->and($question['buckets'][0]['count'])->toBe(2);
});

it('samples free-text answers instead of charting them', function (): void {
    $q = makeQuestion($this->survey, ListFieldType::Textarea, 'WHY');

    answer($this->survey, [$q->id => 'とても良い 🎉']);
    answer($this->survey, [$q->id => 'Could be better']);

    $question = $this->stats->forSurvey($this->survey)['questions'][0];

    expect($question['aggregatable'])->toBeFalse()
        ->and($question['samples'])->toContain('とても良い 🎉')
        ->and($question['samples'])->toContain('Could be better')
        ->and($question['buckets'])->toHaveCount(2);
});

it('counts only completed responses (F-2.8-006)', function (): void {
    $q = makeQuestion($this->survey, ListFieldType::Radio, 'HAPPY', [['Very', 'very']]);

    answer($this->survey, [$q->id => 'very']);
    answer($this->survey, [$q->id => 'very'], SurveyResponderStatus::Incomplete);

    $result = $this->stats->forSurvey($this->survey);

    expect($result['responders'])->toBe(2)
        ->and($result['completed'])->toBe(1)
        ->and($result['questions'][0]['answered'])->toBe(1);
});

it('reports an empty question without dividing by zero', function (): void {
    makeQuestion($this->survey, ListFieldType::Radio, 'UNANSWERED', [['A', 'a']]);

    $question = $this->stats->forSurvey($this->survey)['questions'][0];

    expect($question['answered'])->toBe(0)
        ->and($question['buckets'])->toBe([])
        ->and($question['average'])->toBeNull();
});
