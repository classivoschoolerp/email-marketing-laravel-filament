<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lists;

use App\Enums\OptInType;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Http\Controllers\Controller;
use App\Models\EmailList;
use App\Models\ListForm;
use App\Models\Subscriber;
use App\Services\Captcha\CaptchaManager;
use App\Services\Lists\FieldValueService;
use App\Services\Lists\ListMailer;
use App\Services\Verification\RealTimeGate;
use App\Support\Privacy\IpAnonymizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * Public embed-form POST target (spec §2.2.11): /forms/{list_uid}/subscribe.
 * Honeypot + configured captcha (CaptchaManager) guard the endpoint; the new
 * subscriber is created unconfirmed or confirmed per the list's opt-in mode
 * and the model events fire the list's subscriber-action cascades. Redirects
 * honor the per-form success/error URLs (F-2.2.11-005). Rate-limited at the
 * route. The generated honeypot input is named "website_url_confirm" —
 * humans never see it, bots fill it.
 *
 * WP-09 (§1.5): the hosted subscribe page posts here too, marked with
 * hosted=1 — those submissions answer with the hosted pending/confirmed
 * pages instead of the embed redirects, and successful subscriptions queue
 * the double opt-in confirmation email (double) or the welcome email
 * (single) via ListMailer.
 */
class PublicSubscribeController extends Controller
{
    public const HONEYPOT_FIELD = 'website_url_confirm';

    /** Request flag set by the hosted subscribe page (WP-09, spec §1.5). */
    public const HOSTED_FLAG = 'hosted';

    public function __construct(
        private readonly FieldValueService $fieldValues,
        private readonly ListMailer $mailer,
    ) {}

    public function __invoke(Request $request, string $listUid): RedirectResponse|JsonResponse
    {
        /** @var EmailList|null $list */
        $list = EmailList::withoutTenancy()
            ->where('uid', $listUid)
            ->where('visible', true)
            ->where('status', EmailList::STATUS_ACTIVE)
            ->first();

        abort_if($list === null, 404);

        $form = ListForm::withoutTenancy()->where('list_id', $list->id)->first();

        // Honeypot (spec §1.5): a filled hidden field marks a bot. Pretend
        // success so the bot learns nothing; create nothing.
        if (trim((string) $request->input(self::HONEYPOT_FIELD, '')) !== '') {
            return $this->successRedirect($request, $form, $list, null);
        }

        // Configured captcha via the driver registry (spec §1.5).
        $captcha = CaptchaManager::driver();

        if ($captcha->enabled()
            && ! $captcha->verify($request->input($captcha->fieldName()), $request->ip())) {
            return $this->errorRedirect($request, $form, ['captcha' => __('lists.embed.captcha_failed')]);
        }

        $fields = $form?->orderedFields() ?? $list->fields()->where('visible_on_form', true)->with('options')->get()->all();

        // F-2.2.9-005: a rejoining subscriber must not collide with its own
        // stored values, so resolve the candidate row before validating.
        $currentId = Subscriber::withoutTenancy()
            ->where('list_id', $list->id)
            ->where('email', mb_strtolower(trim((string) $request->input('email'))))
            ->value('id');

        $rules = ['email' => ['required', 'email', 'max:191']];
        $values = ['email' => $request->input('email')];

        foreach ($fields as $field) {
            $rules[$field->tag] = $this->fieldValues->rulesForSubscriber(
                $field,
                $currentId === null ? null : (int) $currentId,
            );
            // F-2.2.9-001: an upload arrives in the file bag, not the input bag.
            $values[$field->tag] = $field->type->isFile()
                ? $request->file($field->tag)
                : $request->input($field->tag);
        }

        $validator = Validator::make($values, $rules);

        if ($validator->fails()) {
            return $this->errorRedirect($request, $form, $validator->errors()->toArray());
        }

        $email = mb_strtolower(trim((string) $request->input('email')));

        // WP-16 (§2.9.12 F-2.9.12-005): real-time verification gate — the ONE
        // verification hook in the public subscribe path (config-gated,
        // default off; WP-19's API insert will call the same gate).
        $gateError = app(RealTimeGate::class)->rejects($list, $email);

        if ($gateError !== null) {
            return $this->errorRedirect($request, $form, ['email' => $gateError]);
        }

        // Status per list opt-in mode: double → unconfirmed until the
        // confirmation email is clicked (WP-09 sends it); single → confirmed.
        $status = $list->opt_in === OptInType::Double
            ? SubscriberStatus::Unconfirmed
            : SubscriberStatus::Confirmed;

        $existing = Subscriber::withoutTenancy()
            ->where('list_id', $list->id)
            ->where('email', $email)
            ->first();

        if ($existing !== null && $existing->status->isActive()) {
            return $this->errorRedirect($request, $form, ['email' => __('lists.embed.already_subscribed')]);
        }

        if ($existing !== null) {
            // Rejoining after unsubscribe: transition through the model so
            // the list's subscriber-action cascades fire (F-2.2.2-015..018).
            $existing->status = $status;
            // WP-22 (§3.25.16 F-3.25.16-005): consent proof records the IP
            // through the anonymiser, so the toggle applies at capture time.
            $existing->subscribe_ip = IpAnonymizer::maybe((string) $request->ip());
            $existing->save();
            $subscriber = $existing;
        } else {
            $subscriber = Subscriber::create([
                'customer_id' => $list->customer_id,
                'list_id' => $list->id,
                'email' => $email,
                'status' => $status,
                'source' => SubscriberSource::Form,
                // WP-22 (§3.25.16 F-3.25.16-005): anonymise at capture time.
                'ip' => IpAnonymizer::maybe((string) $request->ip()),
                'subscribe_ip' => IpAnonymizer::maybe((string) $request->ip()),
            ]);
        }

        foreach ($fields as $field) {
            $value = $values[$field->tag] ?? null;

            // F-2.2.9-001: uploads are stored on the tenant's asset path
            // (D-26) and the field value holds the disk-relative path.
            if ($field->type->isFile()) {
                if ($value instanceof UploadedFile) {
                    $this->fieldValues->set($subscriber, $field, $this->fieldValues->storeUpload($list, $value));
                }

                continue;
            }

            if ($value !== null && $value !== '' && $value !== []) {
                $this->fieldValues->set($subscriber, $field, $value);
            }
        }

        // §1.5 lifecycle emails: double opt-in → queued confirmation email
        // with the signed [CONFIRM_URL] (F-1.5-004); single opt-in →
        // subscriber is already confirmed → welcome email (F-1.5-005).
        if ($subscriber->status === SubscriberStatus::Unconfirmed) {
            $this->mailer->sendSubscribeConfirmation($list, $subscriber);
        } elseif ($subscriber->status === SubscriberStatus::Confirmed) {
            $this->mailer->sendWelcome($list, $subscriber);
        }

        return $this->successRedirect($request, $form, $list, $subscriber);
    }

    private function successRedirect(Request $request, ?ListForm $form, EmailList $list, ?Subscriber $subscriber): RedirectResponse|JsonResponse
    {
        // AJAX submit (F-2.2.11-006): answer JSON so the embedded snippet can
        // render the outcome inline. The configured success URL still travels
        // back (as `redirect`) so F-2.2.11-005 keeps working in AJAX mode.
        if ($this->wantsJson($request)) {
            return response()->json([
                'status' => 'success',
                'message' => $subscriber?->status === SubscriberStatus::Unconfirmed
                    ? __('lists.embed.ajax_pending')
                    : __('lists.embed.ajax_success'),
                'redirect' => $form?->success_url ?: null,
            ]);
        }

        // Hosted flow (WP-09 §1.5): pending page for double opt-in,
        // confirmed page for single. Bots (null subscriber) see the form
        // again with the success flash — indistinguishable from success.
        if ($request->boolean(self::HOSTED_FLAG)) {
            if ($subscriber === null) {
                return redirect()
                    ->route('lists.public.subscribe', ['list_uid' => $list->uid])
                    ->with('subscribe_status', 'success');
            }

            $route = $subscriber->status === SubscriberStatus::Unconfirmed
                ? 'lists.public.pending'
                : 'lists.public.subscribed';

            return redirect()->route($route, [
                'list_uid' => $list->uid,
                'subscriber_uid' => $subscriber->uid,
            ]);
        }

        if ($form?->success_url) {
            return redirect()->away($form->success_url);
        }

        return back()->with('subscribe_status', 'success');
    }

    /** @param array<string, mixed> $errors */
    private function errorRedirect(Request $request, ?ListForm $form, array $errors): RedirectResponse|JsonResponse
    {
        // AJAX submit (F-2.2.11-006): 422 + the field errors, so the snippet
        // can show the first message inline instead of navigating away.
        if ($this->wantsJson($request)) {
            $flat = [];

            foreach ($errors as $key => $messages) {
                $flat[(string) $key] = array_values((array) $messages);
            }

            $first = $flat === [] ? [] : (array) reset($flat);

            return response()->json([
                'status' => 'error',
                'message' => (string) ($first[0] ?? __('lists.embed.ajax_failed')),
                'errors' => $flat,
                'redirect' => $form?->error_url ?: null,
            ], 422);
        }

        // Hosted flow: errors render on the hosted form page, never on the
        // embed form's external error URL.
        if ($request->boolean(self::HOSTED_FLAG)) {
            return back()->withErrors($errors)->withInput($request->except(self::HONEYPOT_FIELD));
        }

        if ($form?->error_url) {
            return redirect()->away($form->error_url);
        }

        return back()->withErrors($errors)->withInput($request->except(self::HONEYPOT_FIELD));
    }

    /**
     * F-2.2.11-006: the caller asked for JSON. The hosted §1.5 page always
     * posts as a browser form, so it never takes this branch.
     */
    private function wantsJson(Request $request): bool
    {
        return ! $request->boolean(self::HOSTED_FLAG)
            && ($request->expectsJson() || $request->ajax());
    }
}
