<?php

declare(strict_types=1);

namespace App\Services\Surveys;

use App\Enums\SegmentConditionType;
use App\Enums\SegmentMatchType;
use App\Enums\SegmentOperator;
use App\Jobs\Surveys\BackfillSurveyAnswersJob;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\ListSegmentCondition;
use App\Models\Survey;
use App\Models\SurveyField;
use RuntimeException;

/**
 * "Create a list segment from survey answers" (spec F-2.8-007).
 *
 * Segments in this product are DYNAMIC (F-2.2.10-012, D-90): they store
 * conditions, never a frozen membership. So the honest way to segment on an
 * answer is to make the answer a property of the subscriber, then let the
 * existing SegmentQueryBuilder do exactly what it already does — rather than
 * bolting a survey-only query language onto the segment engine, which would
 * bypass every operator, preview and campaign-targeting path that already
 * works.
 *
 * That is what {@see ensureMapping()} does: the question gets a list custom
 * field (reusing one if the survey author already mapped it), every past
 * identified answer is backfilled onto its subscriber by a queued job, and
 * future completions write through {@see SurveyResponseService}. The segment
 * is then an ordinary field condition.
 *
 * Anonymous responses are, by definition, not segmentable — there is no
 * subscriber to put in a segment. The UI says so.
 */
class SurveyAnswerSegmentBuilder
{
    /**
     * Ensure the question writes back to a list custom field, creating one
     * if needed, and backfill existing answers.
     */
    public function ensureMapping(Survey $survey, SurveyField $field): ListField
    {
        if ($survey->list_id === null) {
            throw new RuntimeException('Survey '.$survey->uid.' has no list; answers cannot be segmented.');
        }

        if ($field->list_field_id !== null) {
            /** @var ListField|null $existing */
            $existing = ListField::withoutTenancy()->find($field->list_field_id);

            if ($existing !== null && (int) $existing->list_id === (int) $survey->list_id) {
                BackfillSurveyAnswersJob::dispatch((int) $field->id);

                return $existing;
            }
        }

        /** @var ListField $listField */
        $listField = ListField::withoutTenancy()->create([
            'customer_id' => $survey->customer_id,
            'list_id' => $survey->list_id,
            'type' => $field->type,
            'tag' => $this->uniqueTag($survey, $field),
            'label' => $field->label,
            'help_text' => $field->help_text,
            'required' => false,
            // Survey-derived fields are answer storage, not signup inputs.
            'visible_on_form' => false,
            'visible_on_profile' => false,
            'show_in_table' => false,
            'validation' => $field->validation,
            'sort_order' => (int) (ListField::withoutTenancy()
                ->where('list_id', $survey->list_id)
                ->max('sort_order') ?? 0) + 1,
        ]);

        foreach ($field->options as $option) {
            $listField->options()->create([
                'customer_id' => $survey->customer_id,
                'label' => $option->label,
                'value' => $option->value,
                'sort_order' => $option->sort_order,
            ]);
        }

        $field->list_field_id = $listField->id;
        $field->save();

        BackfillSurveyAnswersJob::dispatch((int) $field->id);

        return $listField;
    }

    /**
     * Create the segment. Returns the saved {@see ListSegment}; membership is
     * computed on read, so nothing else has to run.
     */
    public function build(
        Survey $survey,
        SurveyField $field,
        SegmentOperator|string $operator,
        ?string $value,
        ?string $name = null,
    ): ListSegment {
        $listField = $this->ensureMapping($survey, $field);
        $operator = $operator instanceof SegmentOperator ? $operator : SegmentOperator::from($operator);

        /** @var ListSegment $segment */
        $segment = ListSegment::withoutTenancy()->create([
            'customer_id' => $survey->customer_id,
            'list_id' => $survey->list_id,
            'name' => mb_substr(
                trim((string) $name) ?: __('surveys.segment.default_name', [
                    'survey' => $survey->name,
                    'question' => $field->label,
                ]),
                0,
                191,
            ),
            'match_type' => SegmentMatchType::All,
        ]);

        ListSegmentCondition::withoutTenancy()->create([
            'customer_id' => $survey->customer_id,
            'list_segment_id' => $segment->id,
            'type' => SegmentConditionType::Field,
            'list_field_id' => $listField->id,
            'operator' => $operator,
            'value' => $value,
            'sort_order' => 0,
        ]);

        return $segment;
    }

    /**
     * A merge tag that is unique on the list and not one of the reserved
     * schema tags (F-2.2.9-006).
     */
    private function uniqueTag(Survey $survey, SurveyField $field): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', (string) $field->tag) ?: 'ANSWER');
        $base = trim($base, '_') ?: 'ANSWER';

        if (! preg_match('/^[A-Z]/', $base)) {
            $base = 'Q_'.$base;
        }

        $base = mb_substr($base, 0, 56);
        $candidate = $base;
        $suffix = 1;

        while (ListField::isReservedTag($candidate) || $this->tagTaken($survey, $candidate)) {
            $candidate = mb_substr($base, 0, 56 - strlen((string) $suffix) - 1).'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function tagTaken(Survey $survey, string $tag): bool
    {
        return ListField::withoutTenancy()
            ->where('list_id', $survey->list_id)
            ->where('tag', $tag)
            ->exists();
    }
}
