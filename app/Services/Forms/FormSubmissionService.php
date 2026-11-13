<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\OptInType;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Models\ConsentEvent;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\FormVariant;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Observers\ConsentEventObserver;
use App\Services\Automations\AutomationEventDispatcher;
use App\Services\Automations\AutomationEvents;
use App\Services\Lists\FieldValueService;
use App\Services\Lists\ListMailer;
use App\Support\Privacy\IpAnonymizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The one write path of a §2.7 form submission.
 *
 * Order of operations matters and is deliberate:
 *  1. validate against the form's own field library (F-2.7-003);
 *  2. create/transition the subscriber THROUGH THE MODEL, so the list's
 *     subscriber-action cascades (F-2.2.2-015..018) and the §1.5 lifecycle
 *     emails fire exactly as they do for the hosted subscribe page;
 *  3. persist the linked field values (which also feeds the WP-22
 *     ConsentEventObserver for consent-typed fields);
 *  4. record the form_submissions row + GDPR consent proof (F-2.7-009);
 *  5. count the conversion (F-2.7-012);
 *  6. raise `form_submitted` on the automation bus WITH the form id, so
 *     F-2.4.2-011 triggers can filter on one specific form.
 *
 * Step 2 makes AutomationHooks raise its own, form-id-less `form_submitted`
 * approximation; it is muted for the duration of the write so exactly one
 * event — the precise one — reaches the bus.
 *
 * MULTI-STEP (F-2.7-004) CHANGES NONE OF THAT. {@see validateStep()} is a
 * READ-ONLY courtesy for the "Next" button: it validates the inputs of one
 * step and writes NOTHING — no subscriber, no submission, no consent row, no
 * automation event. Consequently:
 *
 *  - partial progress is NOT persisted. Capturing the address from step 1
 *    would be a subscriber-data write made before the visitor pressed submit
 *    and before the consent tick (which lives on the last step) existed, i.e.
 *    a §2.7 lead capture with no lawful basis and no F-2.7-009 audit row. The
 *    only place the half-finished answers live is the visitor's own DOM;
 *  - {@see submit()} still validates EVERY field of the form, so a
 *    hand-crafted POST that skips straight past step 1 is rejected exactly as
 *    it always was — per-step validation is additive, never a replacement;
 *  - exactly one `form_submitted` event is raised, on the final submit (D-96).
 */
class FormSubmissionService
{
    public function __construct(
        private readonly FieldValueService $fieldValues,
        private readonly ListMailer $mailer,
        private readonly FormAnalytics $analytics,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function submit(Form $form, array $input, ?Request $request = null, ?FormVariant $variant = null): FormSubmission
    {
        // Resolved without tenancy: a public submission must not depend on
        // who is logged in (see Form::publicList()).
        $list = $form->publicList();

        abort_if($list === null, 404);

        $fields = $this->fieldsFor($form, $variant);
        $validated = $this->validate($form, $fields, $input);

        $email = mb_strtolower(trim((string) $validated['email']));
        $ip = IpAnonymizer::maybe($request?->ip());

        $status = $list->opt_in === OptInType::Double
            ? SubscriberStatus::Unconfirmed
            : SubscriberStatus::Confirmed;

        // Muted for the whole subscriber write: see the class docblock.
        $subscriber = $this->withoutFormSubmittedEvents(
            function () use ($list, $email, $status, $ip): Subscriber {
                $existing = Subscriber::withoutTenancy()
                    ->where('list_id', $list->id)
                    ->where('email', $email)
                    ->first();

                if ($existing !== null) {
                    // A re-submission by an active subscriber is an update,
                    // never an error: forms are lead capture, not sign-up.
                    if (! $existing->status->isActive()) {
                        $existing->status = $status;
                    }

                    $existing->subscribe_ip = $ip;
                    $existing->save();

                    return $existing;
                }

                return Subscriber::create([
                    'customer_id' => $list->customer_id,
                    'list_id' => $list->id,
                    'email' => $email,
                    'status' => $status,
                    'source' => SubscriberSource::Form,
                    'ip' => $ip,
                    'subscribe_ip' => $ip,
                ]);
            },
        );

        $data = [];

        foreach ($fields as $field) {
            $value = $validated[$field->tag] ?? null;
            $data[$field->tag] = $value;

            if ($field->list_field_id !== null && $value !== null && $value !== '' && $value !== []) {
                // Resolved without tenancy — a public submit has no session.
                $listField = ListField::withoutTenancy()->find($field->list_field_id);

                if ($listField !== null) {
                    $this->fieldValues->set($subscriber, $listField, $value);
                }
            }
        }

        $consentGiven = $form->consent_enabled
            && (bool) filter_var($input[FormRenderer::CONSENT_FIELD] ?? false, FILTER_VALIDATE_BOOLEAN);

        $source = FormAnalytics::normalizeSource(
            (string) ($input[FormRenderer::SOURCE_FIELD] ?? '')
                ?: (string) ($request?->headers->get('referer') ?? ''),
        );

        /** @var FormSubmission $submission */
        $submission = FormSubmission::withoutTenancy()->create([
            'customer_id' => $form->customer_id,
            'form_id' => $form->id,
            'form_variant_id' => $variant?->id,
            'subscriber_id' => $subscriber->id,
            'email' => $email,
            'data' => $data,
            'source' => $source,
            'page_url' => $this->trim((string) ($input[FormRenderer::PAGE_FIELD] ?? ''), 2048),
            'referrer' => $this->trim((string) ($request?->headers->get('referer') ?? ''), 2048),
            'device' => $this->device($request),
            'consent_given' => $consentGiven,
            'consent_text' => $consentGiven ? $this->consentText($form) : null,
            'ip' => $ip,
            'user_agent' => $this->trim((string) ($request?->userAgent() ?? ''), 1000),
        ]);

        if ($consentGiven) {
            $this->logConsent($form, $subscriber, $ip, $request);
        }

        // §1.5 lifecycle emails, identical to the hosted subscribe page.
        if ($subscriber->status === SubscriberStatus::Unconfirmed) {
            $this->mailer->sendSubscribeConfirmation($list, $subscriber);
        } elseif ($subscriber->wasRecentlyCreated && $subscriber->status === SubscriberStatus::Confirmed) {
            $this->mailer->sendWelcome($list, $subscriber);
        }

        $this->analytics->recordSubmission($form, $source, $variant);

        // F-2.4.2-011: the real producer, with the form id in the context.
        // Automations are an optional module; without it there is no consumer.
        if (class_exists(AutomationEventDispatcher::class)) {
            app(AutomationEventDispatcher::class)->record(
                AutomationEvents::FORM_SUBMITTED,
                $subscriber,
                ['form_id' => (int) $form->id, 'list_id' => (int) $subscriber->list_id, 'source' => $source],
            );
        }

        return $submission;
    }

    /**
     * @param  list<FormField>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(Form $form, array $fields, array $input): array
    {
        $rules = ['email' => ['required', 'email', 'max:191']];
        $values = ['email' => $input['email'] ?? null];
        $names = ['email' => trim((string) $form->email_label) ?: __('forms.public.email_label')];

        foreach ($fields as $field) {
            $rules[$field->tag] = $this->fieldValues->rulesFor($field);
            $values[$field->tag] = $input[$field->tag] ?? null;
            $names[$field->tag] = $field->label;
        }

        if ($form->consent_enabled && $form->consent_required) {
            $rules[FormRenderer::CONSENT_FIELD] = ['accepted'];
            $values[FormRenderer::CONSENT_FIELD] = $input[FormRenderer::CONSENT_FIELD] ?? null;
            $names[FormRenderer::CONSENT_FIELD] = __('forms.public.consent_attribute');
        }

        return Validator::make($values, $rules, [], $names)->validate();
    }

    /**
     * Validate ONE step of a multi-step form (F-2.7-004), so a visitor learns
     * that their email address is malformed while leaving step 1 rather than
     * after filling in step 3.
     *
     * Read-only by construction — it returns validated values and touches no
     * table (see the class docblock on why partial progress is not persisted).
     * It is a UX affordance layered ON TOP of {@see validate()}, which still
     * runs over every field at submit: skipping this call, or lying about the
     * step number, changes nothing about what is accepted.
     *
     * The step's own inputs are validated, plus the built-in email on step 1
     * and consent on the last step — the exact split
     * {@see FormRenderer::stepSections()} renders, because both read their
     * grouping from {@see FormSteps::group()}.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateStep(Form $form, int $step, array $input, ?FormVariant $variant = null): array
    {
        $count = FormSteps::count($form);
        $step = max(1, min($count, $step));
        $groups = FormSteps::group($this->fieldsFor($form, $variant), $count);

        $rules = [];
        $values = [];
        $names = [];

        if ($step === 1) {
            $rules['email'] = ['required', 'email', 'max:191'];
            $values['email'] = $input['email'] ?? null;
            $names['email'] = trim((string) $form->email_label) ?: __('forms.public.email_label');
        }

        foreach ($groups[$step] as $field) {
            $rules[$field->tag] = $this->fieldValues->rulesFor($field);
            $values[$field->tag] = $input[$field->tag] ?? null;
            $names[$field->tag] = $field->label;
        }

        if ($step === $count && $form->consent_enabled && $form->consent_required) {
            $rules[FormRenderer::CONSENT_FIELD] = ['accepted'];
            $values[FormRenderer::CONSENT_FIELD] = $input[FormRenderer::CONSENT_FIELD] ?? null;
            $names[FormRenderer::CONSENT_FIELD] = __('forms.public.consent_attribute');
        }

        return Validator::make($values, $rules, [], $names)->validate();
    }

    /**
     * The field set in force: an A/B arm may show a different subset, in a
     * different order (F-2.7-013), and the visitor can only be held to the
     * inputs they were actually shown.
     *
     * @return list<FormField>
     */
    private function fieldsFor(Form $form, ?FormVariant $variant): array
    {
        $fields = $form->orderedFields();

        return $variant === null ? $fields : $variant->effectiveFields($fields);
    }

    /**
     * GDPR consent proof (F-2.7-009). When the form points at a Consent-type
     * list field the tick is written through FieldValueService, so the WP-22
     * ConsentEventObserver produces the audit row exactly as it does for the
     * hosted pages. Without such a field the proof is written directly, still
     * into `consent_events` — one audit table, no parallel log.
     */
    private function logConsent(Form $form, Subscriber $subscriber, ?string $ip, ?Request $request): void
    {
        $consentField = $form->publicConsentField();

        if ($consentField !== null) {
            $this->fieldValues->set($subscriber, $consentField, true);

            return;
        }

        ConsentEvent::withoutTenancy()->create([
            'customer_id' => $form->customer_id,
            'subscriber_id' => $subscriber->id,
            'list_field_id' => null,
            'field' => 'FORM_CONSENT',
            'old_value' => null,
            'new_value' => '1',
            'ip' => $ip,
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 512),
            'source' => ConsentEventObserver::source(),
            'created_at' => now(),
        ]);
    }

    private function consentText(Form $form): string
    {
        return mb_substr(trim((string) $form->consent_text) ?: __('forms.public.consent_default'), 0, 2000);
    }

    /** Coarse device bucket for the analytics breakdown. */
    private function device(?Request $request): ?string
    {
        $agent = mb_strtolower((string) ($request?->userAgent() ?? ''));

        if ($agent === '') {
            return null;
        }

        return match (true) {
            str_contains($agent, 'ipad') || str_contains($agent, 'tablet') => 'tablet',
            str_contains($agent, 'mobi') || str_contains($agent, 'android') => 'mobile',
            default => 'desktop',
        };
    }

    private function trim(string $value, int $length): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    /**
     * Runs the subscriber write with the automation FORM_SUBMITTED producer
     * muted when the automations module is installed.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withoutFormSubmittedEvents(callable $callback): mixed
    {
        if (! class_exists(AutomationEventDispatcher::class)) {
            return $callback();
        }

        return AutomationEventDispatcher::mute(AutomationEvents::FORM_SUBMITTED, $callback);
    }
}
