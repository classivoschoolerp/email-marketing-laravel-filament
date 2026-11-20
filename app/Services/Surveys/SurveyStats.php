<?php

declare(strict_types=1);

namespace App\Services\Surveys;

use App\Enums\ListFieldType;
use App\Enums\SurveyResponderStatus;
use App\Models\Survey;
use App\Models\SurveyField;
use App\Models\SurveyResponderAnswer;
use App\Support\Geo\Countries;

/**
 * Per-question aggregates behind the §2.8 stats page (F-2.8-006).
 *
 * Only COMPLETED responses are counted: a half-finished wizard is not an
 * opinion, and including it would make the percentages disagree with the
 * responder count shown everywhere else.
 *
 * Multi-value answers (multiselect / checkbox list) are exploded, so the
 * counts add up to "times chosen", not "responses", and the chart is honest
 * about that by reporting `total_selections` alongside `answered`.
 */
class SurveyStats
{
    /**
     * @return array{responders:int, completed:int, questions:list<array<string, mixed>>}
     */
    public function forSurvey(Survey $survey): array
    {
        $completedIds = $survey->responders()
            ->where('status', SurveyResponderStatus::Complete->value)
            ->pluck('id')
            ->all();

        $questions = [];

        foreach ($survey->orderedFields() as $field) {
            $questions[] = $this->forField($field, $completedIds);
        }

        return [
            'responders' => (int) $survey->responders()->count(),
            'completed' => count($completedIds),
            'questions' => $questions,
        ];
    }

    /**
     * @param  list<int>  $responderIds
     * @return array{
     *     field_id:int, label:string, type:string, aggregatable:bool,
     *     answered:int, total_selections:int, average:float|null,
     *     buckets:list<array{label:string, value:string, count:int, percent:float}>,
     *     samples:list<string>
     * }
     */
    public function forField(SurveyField $field, array $responderIds): array
    {
        $raw = $responderIds === []
            ? collect()
            : SurveyResponderAnswer::withoutTenancy()
                ->where('survey_field_id', $field->id)
                ->whereIn('survey_responder_id', $responderIds)
                ->pluck('value');

        $values = $raw
            ->map(fn (mixed $value): ?string => $value === null ? null : (string) $value)
            ->filter(fn (?string $value): bool => $value !== null && $value !== '')
            ->values();

        $answered = $values->count();
        $counts = [];
        $selections = 0;
        $numeric = [];
        $samples = [];

        foreach ($values as $stored) {
            $decoded = $field->type->isMultiValue()
                ? (array) (json_decode($stored, true) ?: [$stored])
                : [$stored];

            foreach ($decoded as $single) {
                $single = (string) $single;
                $counts[$single] = ($counts[$single] ?? 0) + 1;
                $selections++;

                if ($field->type->isNumeric() && is_numeric($single)) {
                    $numeric[] = (float) $single;
                }
            }

            if (! $field->isAggregatable() && count($samples) < 20) {
                $samples[] = mb_substr($stored, 0, 200);
            }
        }

        arsort($counts);

        $buckets = [];

        foreach ($counts as $value => $count) {
            $buckets[] = [
                'label' => $this->optionLabel($field, (string) $value),
                'value' => (string) $value,
                'count' => $count,
                'percent' => $selections > 0 ? round(($count / $selections) * 100, 1) : 0.0,
            ];
        }

        return [
            'field_id' => (int) $field->id,
            'label' => (string) $field->label,
            'type' => $field->type->value,
            'aggregatable' => $field->isAggregatable(),
            'answered' => $answered,
            'total_selections' => $selections,
            'average' => $numeric === [] ? null : round(array_sum($numeric) / count($numeric), 2),
            'buckets' => $field->isAggregatable() ? $buckets : array_slice($buckets, 0, 10),
            'samples' => $samples,
        ];
    }

    /** Human label for a stored answer value. */
    private function optionLabel(SurveyField $field, string $value): string
    {
        if ($field->type->hasOptions()) {
            foreach ($field->options as $option) {
                if ((string) $option->value === $value) {
                    return (string) $option->label;
                }
            }

            return $value;
        }

        if ($field->type->isBoolean()) {
            return $value === '1' ? __('surveys.stats.yes') : __('surveys.stats.no');
        }

        if ($field->type === ListFieldType::Country) {
            return Countries::all()[strtoupper($value)] ?? $value;
        }

        return $value;
    }
}
