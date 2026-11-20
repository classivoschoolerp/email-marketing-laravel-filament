<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Models\Survey;
use App\Models\SurveyResponder;
use App\Services\Captcha\CaptchaManager;
use App\Services\Lists\FieldValueService;
use App\Services\Surveys\SurveyResponseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Public survey pages (spec §1.9, serving the §2.8 module).
 *
 * Access model matches the rest of the public frontend: the survey uid and
 * the responder uid ARE the secrets (unguessable uuids), so the multi-step
 * wizard needs no session and survives a shared link, a new tab or a
 * bookmarked resume. Tenancy is never consulted — a uid from another
 * customer is a 404.
 *
 * F-1.9-004: every entry point re-evaluates {@see Survey::isOpen()}, so the
 * end date and the responder cap close the survey without a scheduler.
 * Already-started responses are allowed to FINISH once the cap is hit —
 * refusing mid-wizard would throw away work the visitor already did — but a
 * new response is refused.
 */
class SurveyPublicController extends Controller
{
    public function __construct(
        private readonly SurveyResponseService $responses,
        private readonly FieldValueService $fieldValues,
    ) {}

    /** GET /surveys/{uid} — start page (F-2.8-004). */
    public function start(Request $request, string $uid): Response|RedirectResponse
    {
        $survey = $this->resolve($uid);
        $subscriber = $this->responses->identify($survey, $request->query('s') === null ? null : (string) $request->query('s'));

        if (! $survey->isOpen()) {
            return $this->closed($survey);
        }

        if (! $survey->allow_anonymous && $subscriber === null) {
            return $this->closed($survey, 'identified_only');
        }

        if ($this->responses->alreadyResponded($survey, $subscriber)) {
            return $this->closed($survey, 'already_responded');
        }

        if ($survey->orderedFields() === []) {
            return $this->closed($survey, 'no_questions');
        }

        return response()->view('public.surveys.start', [
            'survey' => $survey,
            'subscriber' => $subscriber,
            'title' => $survey->publicName(),
        ]);
    }

    /** POST /surveys/{uid}/start — creates (or resumes) the response. */
    public function begin(Request $request, string $uid): RedirectResponse|Response
    {
        $survey = $this->resolve($uid);

        if (! $survey->isOpen()) {
            return $this->closed($survey);
        }

        // Honeypot: a bot gets the thank-you page and creates nothing.
        if (trim((string) $request->input(PublicSubscribeController::HONEYPOT_FIELD, '')) !== '') {
            return response()->view('public.surveys.thanks', [
                'survey' => $survey,
                'title' => $survey->publicName(),
            ]);
        }

        $captcha = CaptchaManager::driver();

        if ($captcha->enabled()
            && ! $captcha->verify($request->input($captcha->fieldName()), $request->ip())) {
            return back()->withErrors(['captcha' => __('lists.embed.captcha_failed')]);
        }

        $subscriber = $this->responses->identify($survey, $request->input('s') === null ? null : (string) $request->input('s'));

        if (! $survey->allow_anonymous && $subscriber === null) {
            return $this->closed($survey, 'identified_only');
        }

        if ($this->responses->alreadyResponded($survey, $subscriber)) {
            return $this->closed($survey, 'already_responded');
        }

        $responder = $this->responses->start($survey, $subscriber, $request);

        return redirect()->route('surveys.public.step', [
            'uid' => $survey->uid,
            'responder' => $responder->uid,
            'step' => 1,
        ]);
    }

    /** GET step — one wizard page with the progress bar (F-1.9-001). */
    public function step(string $uid, string $responderUid, int $step): Response|RedirectResponse
    {
        $survey = $this->resolve($uid);
        $responder = $this->resolveResponder($survey, $responderUid);

        if ($responder->isComplete()) {
            return redirect()->route('surveys.public.thanks', [
                'uid' => $survey->uid, 'responder' => $responder->uid,
            ]);
        }

        $steps = $survey->steps();

        abort_if($step < 1 || $step > count($steps), 404);

        $values = [];

        foreach ($this->responses->answers($responder, $survey) as $fieldId => $value) {
            $values[$fieldId] = $value;
        }

        return response()->view('public.surveys.step', [
            'survey' => $survey,
            'responder' => $responder,
            'fields' => $steps[$step - 1],
            'step' => $step,
            'stepCount' => count($steps),
            'values' => $this->byTag($survey, $values),
            'title' => $survey->publicName(),
        ]);
    }

    /** POST step — validate + save, then advance or complete (F-1.9-002). */
    public function saveStep(Request $request, string $uid, string $responderUid, int $step): RedirectResponse|Response
    {
        $survey = $this->resolve($uid);
        $responder = $this->resolveResponder($survey, $responderUid);

        if ($responder->isComplete()) {
            return redirect()->route('surveys.public.thanks', [
                'uid' => $survey->uid, 'responder' => $responder->uid,
            ]);
        }

        $steps = $survey->steps();

        abort_if($step < 1 || $step > count($steps), 404);

        if (trim((string) $request->input(PublicSubscribeController::HONEYPOT_FIELD, '')) !== '') {
            return redirect()->route('surveys.public.thanks', [
                'uid' => $survey->uid, 'responder' => $responder->uid,
            ]);
        }

        try {
            $this->responses->saveStep($responder, $survey, $step, $request->all());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        if ($step < count($steps)) {
            return redirect()->route('surveys.public.step', [
                'uid' => $survey->uid, 'responder' => $responder->uid, 'step' => $step + 1,
            ]);
        }

        $this->responses->complete($responder, $survey);

        // F-1.9-003: an explicit redirect wins over the thank-you page.
        if (trim((string) $survey->redirect_url) !== '') {
            return redirect()->away((string) $survey->redirect_url);
        }

        return redirect()->route('surveys.public.thanks', [
            'uid' => $survey->uid, 'responder' => $responder->uid,
        ]);
    }

    /** GET thank-you page (F-1.9-003). */
    public function thanks(string $uid, string $responderUid): Response|RedirectResponse
    {
        $survey = $this->resolve($uid);
        $responder = $this->resolveResponder($survey, $responderUid);

        if (! $responder->isComplete()) {
            return redirect()->route('surveys.public.step', [
                'uid' => $survey->uid,
                'responder' => $responder->uid,
                'step' => min((int) $responder->current_step, $survey->stepCount()),
            ]);
        }

        if (trim((string) $survey->redirect_url) !== '') {
            return redirect()->away((string) $survey->redirect_url);
        }

        return response()->view('public.surveys.thanks', [
            'survey' => $survey,
            'responder' => $responder,
            'title' => $survey->publicName(),
        ]);
    }

    /**
     * Answers keyed by merge tag, for the field partial (which is shared
     * with the §1.5 list pages and keys off the tag).
     *
     * @param  array<int, mixed>  $byId
     * @return array<string, mixed>
     */
    private function byTag(Survey $survey, array $byId): array
    {
        $values = [];

        foreach ($survey->orderedFields() as $field) {
            $value = $byId[(int) $field->id] ?? null;

            $values[$field->tag] = $field->type->isDate() && $value instanceof \DateTimeInterface
                ? $value->format($field->type->value === 'datetime' ? 'Y-m-d H:i:s' : 'Y-m-d')
                : $value;
        }

        return $values;
    }

    private function resolve(string $uid): Survey
    {
        /** @var Survey|null $survey */
        $survey = Survey::withoutTenancy()->where('uid', $uid)->first();

        abort_if($survey === null, 404);

        return $survey;
    }

    private function resolveResponder(Survey $survey, string $uid): SurveyResponder
    {
        /** @var SurveyResponder|null $responder */
        $responder = SurveyResponder::withoutTenancy()
            ->where('survey_id', $survey->id)
            ->where('uid', $uid)
            ->first();

        abort_if($responder === null, 404);

        return $responder;
    }

    private function closed(Survey $survey, ?string $reason = null): Response
    {
        return response()->view('public.surveys.closed', [
            'survey' => $survey,
            'reason' => $reason ?? $survey->closedReason(),
            'title' => $survey->publicName(),
        ], 200);
    }
}
