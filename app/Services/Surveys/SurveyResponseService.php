<?php

declare(strict_types=1);

namespace App\Services\Surveys;

use App\Enums\SurveyResponderStatus;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\Survey;
use App\Models\SurveyField;
use App\Models\SurveyResponder;
use App\Models\SurveyResponderAnswer;
use App\Services\Automations\AutomationEventDispatcher;
use App\Services\Automations\AutomationEvents;
use App\Services\Lists\FieldValueService;
use App\Support\Privacy\IpAnonymizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The write path of a survey response (spec §1.9 / F-2.8-005/009).
 *
 * Answers are stored text-backed with the SAME codec as subscriber field
 * values ({@see FieldValueService::toStorage()}), so a multiselect answer,
 * a rating and a date all round-trip identically wherever they are read —
 * responders table, CSV export, aggregate stats, segment write-back.
 *
 * Identification (F-1.9-002 / F-2.8-009): the public URL may carry
 * `?s={subscriber_uid}`. When it resolves inside the survey's own tenant the
 * response is linked to that subscriber; otherwise it is anonymous, and an
 * anonymous response simply has nobody to enrol in an automation.
 */
class SurveyResponseService
{
    public function __construct(private readonly FieldValueService $fieldValues) {}

    /**
     * Resolve `?s=` into a subscriber of this survey's tenant. Cross-tenant
     * or unknown uids resolve to NULL (anonymous) rather than erroring: a
     * mistyped link must never leak whether a subscriber exists.
     */
    public function identify(Survey $survey, ?string $subscriberUid): ?Subscriber
    {
        if ($subscriberUid === null || trim($subscriberUid) === '') {
            return null;
        }

        /** @var Subscriber|null $subscriber */
        $subscriber = Subscriber::withoutTenancy()
            ->where('uid', trim($subscriberUid))
            ->where('customer_id', $survey->customer_id)
            ->when($survey->list_id !== null, fn ($query) => $query->where('list_id', $survey->list_id))
            ->first();

        return $subscriber;
    }

    /** Has this subscriber already completed the survey? (F-2.8-002 cap) */
    public function alreadyResponded(Survey $survey, ?Subscriber $subscriber): bool
    {
        if ($subscriber === null || ! $survey->one_response_per_subscriber) {
            return false;
        }

        return SurveyResponder::withoutTenancy()
            ->where('survey_id', $survey->id)
            ->where('subscriber_id', $subscriber->id)
            ->where('status', SurveyResponderStatus::Complete->value)
            ->exists();
    }

    /**
     * Start (or resume) a response. An identified subscriber with an
     * unfinished attempt continues it instead of forking a second row.
     */
    public function start(Survey $survey, ?Subscriber $subscriber, ?Request $request = null): SurveyResponder
    {
        if ($subscriber !== null) {
            /** @var SurveyResponder|null $existing */
            $existing = SurveyResponder::withoutTenancy()
                ->where('survey_id', $survey->id)
                ->where('subscriber_id', $subscriber->id)
                ->where('status', SurveyResponderStatus::Incomplete->value)
                ->latest('id')
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        /** @var SurveyResponder $responder */
        $responder = SurveyResponder::withoutTenancy()->create([
            'customer_id' => $survey->customer_id,
            'survey_id' => $survey->id,
            'subscriber_id' => $subscriber?->id,
            'email' => $subscriber?->email,
            'status' => SurveyResponderStatus::Incomplete,
            'current_step' => 1,
            'ip' => IpAnonymizer::maybe($request?->ip()),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 1000),
        ]);

        return $responder;
    }

    /**
     * Validate + persist one wizard step (F-1.9-001).
     *
     * @param  array<string, mixed>  $input
     * @return list<SurveyField> the fields that were saved
     *
     * @throws ValidationException
     */
    public function saveStep(SurveyResponder $responder, Survey $survey, int $step, array $input): array
    {
        $steps = $survey->steps();
        $fields = $steps[$step - 1] ?? [];

        if ($fields === []) {
            return [];
        }

        $rules = [];
        $values = [];
        $names = [];

        foreach ($fields as $field) {
            $rules[$field->tag] = $this->fieldValues->rulesFor($field);
            $values[$field->tag] = $input[$field->tag] ?? null;
            $names[$field->tag] = $field->label;
        }

        $validated = Validator::make($values, $rules, [], $names)->validate();

        foreach ($fields as $field) {
            SurveyResponderAnswer::withoutTenancy()->updateOrCreate(
                [
                    'survey_responder_id' => $responder->id,
                    'survey_field_id' => $field->id,
                ],
                [
                    'customer_id' => $responder->customer_id,
                    'value' => $this->fieldValues->toStorage($field, $validated[$field->tag] ?? null),
                ],
            );
        }

        $responder->current_step = max((int) $responder->current_step, $step + 1);
        $responder->save();

        return $fields;
    }

    /**
     * Finish a response (F-1.9-002/003):
     *  - flip to Complete (idempotent — a refreshed thank-you page must not
     *    double-count against max_responders);
     *  - write mapped answers onto the subscriber, which is what makes
     *    F-2.8-007 "segment from answers" work with the existing dynamic
     *    segment engine;
     *  - raise `survey_completed` with the survey id so F-2.4.2-012 triggers
     *    can filter on one survey.
     */
    public function complete(SurveyResponder $responder, Survey $survey): SurveyResponder
    {
        if ($responder->isComplete()) {
            return $responder;
        }

        $responder->status = SurveyResponderStatus::Complete;
        $responder->completed_at = now();
        $responder->save();

        $subscriber = $responder->subscriber_id === null
            ? null
            : Subscriber::withoutTenancy()->find($responder->subscriber_id);

        if ($subscriber !== null) {
            $this->writeBack($responder, $survey, $subscriber);

            // F-2.4.2-012. Anonymous responses have nobody to enrol, which is
            // exactly what "anonymous" means — no event is raised for them.
            app(AutomationEventDispatcher::class)->record(
                AutomationEvents::SURVEY_COMPLETED,
                $subscriber,
                [
                    'survey_id' => (int) $survey->id,
                    'survey_uid' => (string) $survey->uid,
                    'responder_id' => (int) $responder->id,
                ],
            );
        }

        return $responder;
    }

    /** Copy mapped answers onto the subscriber's list custom fields. */
    private function writeBack(SurveyResponder $responder, Survey $survey, Subscriber $subscriber): void
    {
        // Without tenancy: completion happens on a public request.
        $fields = collect($survey->orderedFields())
            ->filter(fn (SurveyField $field): bool => $field->list_field_id !== null);

        if ($fields->isEmpty()) {
            return;
        }

        $answers = SurveyResponderAnswer::withoutTenancy()
            ->where('survey_responder_id', $responder->id)
            ->pluck('value', 'survey_field_id');

        foreach ($fields as $field) {
            $listField = ListField::withoutTenancy()->find($field->list_field_id);

            if ($listField === null || $listField->list_id !== $subscriber->list_id) {
                continue;
            }

            $raw = $answers->get($field->id);

            if ($raw === null || $raw === '') {
                continue;
            }

            $this->fieldValues->set($subscriber, $listField, $this->fieldValues->fromStorage($field, (string) $raw));
        }
    }

    /**
     * Typed answers for one responder, keyed by survey_field_id.
     *
     * @return array<int, mixed>
     */
    public function answers(SurveyResponder $responder, Survey $survey): array
    {
        $raw = SurveyResponderAnswer::withoutTenancy()
            ->where('survey_responder_id', $responder->id)
            ->pluck('value', 'survey_field_id');

        $typed = [];

        foreach ($survey->orderedFields() as $field) {
            $value = $raw->get($field->id);
            $typed[(int) $field->id] = $this->fieldValues->fromStorage($field, $value === null ? null : (string) $value);
        }

        return $typed;
    }
}
