<?php

declare(strict_types=1);

namespace App\Jobs\Surveys;

use App\Enums\SurveyResponderStatus;
use App\Models\Customer;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\SurveyField;
use App\Models\SurveyResponder;
use App\Models\SurveyResponderAnswer;
use App\Services\Lists\FieldValueService;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Copies existing answers to one survey question onto the subscribers who
 * gave them (spec F-2.8-007 groundwork).
 *
 * Runs when a question is mapped to a list custom field after responses have
 * already arrived — without it, a segment built from an old survey would
 * match nobody. Queued because a popular survey can have hundreds of
 * thousands of completed responses (docs/ARCHITECTURE.md rule 5), and chunked so memory
 * stays flat.
 *
 * Only COMPLETED, IDENTIFIED responses are backfilled: an anonymous response
 * has no subscriber, and a half-finished one is not an answer.
 *
 * Deliberately NOT an IdempotentJob: writing the same answer twice is a
 * no-op (updateOrCreate), and a permanent at-most-once key would silently
 * drop the re-run that a re-mapped question needs.
 */
class BackfillSurveyAnswersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public readonly int $surveyFieldId)
    {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(CurrentCustomer $current, FieldValueService $fieldValues): void
    {
        /** @var SurveyField|null $field */
        $field = SurveyField::withoutTenancy()->find($this->surveyFieldId);

        if ($field === null || $field->list_field_id === null) {
            return;
        }

        /** @var ListField|null $listField */
        $listField = ListField::withoutTenancy()->find($field->list_field_id);
        $customer = Customer::query()->find($field->customer_id);

        if ($listField === null || $customer === null) {
            return;
        }

        $current->runAs($customer, function () use ($field, $listField, $fieldValues): void {
            SurveyResponderAnswer::withoutTenancy()
                ->where('survey_field_id', $field->id)
                ->orderBy('id')
                ->chunkById(500, function ($answers) use ($field, $listField, $fieldValues): void {
                    $responders = SurveyResponder::withoutTenancy()
                        ->whereIn('id', $answers->pluck('survey_responder_id')->all())
                        ->where('status', SurveyResponderStatus::Complete->value)
                        ->whereNotNull('subscriber_id')
                        ->get()
                        ->keyBy('id');

                    if ($responders->isEmpty()) {
                        return;
                    }

                    $subscribers = Subscriber::withoutTenancy()
                        ->whereIn('id', $responders->pluck('subscriber_id')->all())
                        ->where('list_id', $listField->list_id)
                        ->get()
                        ->keyBy('id');

                    foreach ($answers as $answer) {
                        $responder = $responders->get($answer->survey_responder_id);

                        if ($responder === null) {
                            continue;
                        }

                        $subscriber = $subscribers->get($responder->subscriber_id);

                        if ($subscriber === null || $answer->value === null || $answer->value === '') {
                            continue;
                        }

                        $fieldValues->set(
                            $subscriber,
                            $listField,
                            $fieldValues->fromStorage($field, (string) $answer->value),
                        );
                    }
                });
        });
    }
}
