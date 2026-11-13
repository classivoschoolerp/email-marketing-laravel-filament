<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\ListPageType;
use App\Enums\SendFrequency;
use App\Enums\SubscriberStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Http\Controllers\Public\Concerns\ResolvesPublicList;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Captcha\CaptchaManager;
use App\Services\Lists\FieldValueService;
use App\Services\Lists\ListMailer;
use App\Services\Lists\ListPageResolver;
use App\Services\Lists\PublicPageRenderer;
use App\Services\Lists\SubscriberPreferences;
use App\Support\Privacy\IpAnonymizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Hosted public list pages (spec §1.5): subscribe form, pending/confirm,
 * update profile, the 3-step unsubscribe flow and the RFC 8058 one-click
 * endpoint. Content comes from PublicPageRenderer (3-layer merge + merge
 * tags); every page honors the per-page redirect override plus the
 * list-wide Redirect page type (F-1.5-010) and carries the CAN-SPAM footer
 * (F-1.5-014) and per-page custom CSS/JS (F-1.5-017).
 *
 * Access model: the subscriber uid in the URL is the secret (MailWizz
 * pattern) — uids are unguessable UUIDs, so no session/signature is needed
 * for profile/unsubscribe pages; the double opt-in confirm link is
 * additionally signed (route middleware).
 */
class ListPagesController extends Controller
{
    use ResolvesPublicList;

    /** Unsubscribe reason dropdown option keys (F-1.5-007). */
    public const UNSUBSCRIBE_REASONS = [
        'no_longer_interested',
        'too_many_emails',
        'never_subscribed',
        'content_not_relevant',
        'other',
    ];

    public function __construct(
        private readonly ListPageResolver $resolver,
        private readonly PublicPageRenderer $renderer,
        private readonly FieldValueService $fieldValues,
        private readonly ListMailer $mailer,
        private readonly SubscriberPreferences $preferences,
    ) {}

    /** GET /lists/{uid}/subscribe — the hosted subscribe form (F-1.5-001). */
    public function subscribeForm(string $listUid): Response|RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);

        return $this->renderPage($list, ListPageType::SubscribeForm, null, 'public.lists.subscribe', [
            'fields' => $this->formFields($list, 'visible_on_form'),
            'values' => [],
        ]);
    }

    /** GET pending page — double opt-in "check your inbox" (F-1.5-002). */
    public function pending(string $listUid, string $subscriberUid): Response|RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        return $this->renderPage($list, ListPageType::SubscribePending, $subscriber);
    }

    /** GET confirmed page — subscription success display (F-1.5-003). */
    public function confirmed(string $listUid, string $subscriberUid): Response|RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        return $this->renderPage($list, ListPageType::SubscribeConfirm, $subscriber);
    }

    /**
     * GET /lists/{uid}/confirm-subscription/{subscriber_uid} — the signed
     * double opt-in link (F-1.5-003/004). Unconfirmed → Confirmed through
     * the model (cascades fire), then the welcome email (F-1.5-005).
     */
    public function confirmSubscription(Request $request, string $listUid, string $subscriberUid): Response|RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        if ($subscriber->status === SubscriberStatus::Unconfirmed) {
            $subscriber->status = SubscriberStatus::Confirmed;
            // WP-22 (§3.25.16 F-3.25.16-005): the double opt-in proof IP is
            // recorded through the anonymiser (§4.5 F-4.5-004 keeps the
            // timestamp + IP pair; the toggle only truncates the host part).
            $subscriber->confirm_ip = IpAnonymizer::maybe((string) $request->ip());
            $subscriber->save();

            $this->mailer->sendWelcome($list, $subscriber);
        }

        return $this->renderPage($list, ListPageType::SubscribeConfirm, $subscriber);
    }

    /** GET update-profile form (F-1.5-006). */
    public function updateProfileForm(string $listUid, string $subscriberUid): Response|RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        $fields = $this->formFields($list, 'visible_on_profile');
        $values = [];

        foreach ($fields as $field) {
            $values[$field->tag] = $this->fieldValues->formValue($field, $this->fieldValues->raw($subscriber, $field));
        }

        return $this->renderPage($list, ListPageType::UpdateProfile, $subscriber, 'public.lists.update-profile', [
            'fields' => $fields,
            'values' => $values,
        ]);
    }

    /** POST update-profile — save email + visible_on_profile fields. */
    public function updateProfileSave(Request $request, string $listUid, string $subscriberUid): RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        // Honeypot (F-1.5-016): pretend success, change nothing.
        if (trim((string) $request->input(PublicSubscribeController::HONEYPOT_FIELD, '')) !== '') {
            return back()->with('public_status', 'profile_updated');
        }

        if (($captchaError = $this->verifyCaptcha($request)) !== null) {
            return back()->withErrors(['captcha' => $captchaError])->withInput();
        }

        $fields = $this->formFields($list, 'visible_on_profile');

        $rules = ['email' => [
            'required', 'email', 'max:191',
            Rule::unique('subscribers', 'email')
                ->where('list_id', $list->id)
                ->whereNull('deleted_at')
                ->ignore($subscriber->id),
        ]];
        $values = ['email' => $request->input('email')];

        foreach ($fields as $field) {
            // F-2.2.9-005: unique values, ignoring this subscriber's own row.
            $rules[$field->tag] = $this->fieldValues->rulesForSubscriber($field, (int) $subscriber->id);
            // F-2.2.9-001: an upload arrives in the file bag, not the input bag.
            $values[$field->tag] = $field->type->isFile()
                ? $request->file($field->tag)
                : $request->input($field->tag);
        }

        $validator = Validator::make($values, $rules);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $subscriber->email = mb_strtolower(trim((string) $request->input('email')));
        $subscriber->save();

        foreach ($fields as $field) {
            // F-2.2.9-001 uploads: keep the current file when none was sent,
            // otherwise store the new one on the tenant's asset path (D-26).
            if ($field->type->isFile()) {
                $upload = $values[$field->tag] ?? null;

                if ($upload instanceof UploadedFile) {
                    $this->fieldValues->set($subscriber, $field, $this->fieldValues->storeUpload($list, $upload));
                }

                continue;
            }

            $this->fieldValues->set($subscriber, $field, $request->input($field->tag));
        }

        return back()->with('public_status', 'profile_updated');
    }

    /** GET unsubscribe page — reason dropdown + free text (F-1.5-007). */
    public function unsubscribeForm(string $listUid, string $subscriberUid): Response|RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        if ($subscriber->status === SubscriberStatus::Unsubscribed) {
            return redirect()->route('lists.public.unsubscribe.success', [
                'list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid,
            ]);
        }

        return $this->renderPage($list, ListPageType::UnsubscribeForm, $subscriber, 'public.lists.unsubscribe', [
            'reasons' => self::UNSUBSCRIBE_REASONS,
        ]);
    }

    /** POST unsubscribe — validates the reason, shows the confirm page (F-1.5-008). */
    public function unsubscribeConfirm(Request $request, string $listUid, string $subscriberUid): Response|RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        // Honeypot: bots see the success page; nothing changes.
        if (trim((string) $request->input(PublicSubscribeController::HONEYPOT_FIELD, '')) !== '') {
            return redirect()->route('lists.public.unsubscribe.success', [
                'list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid,
            ]);
        }

        if (($captchaError = $this->verifyCaptcha($request)) !== null) {
            return back()->withErrors(['captcha' => $captchaError])->withInput();
        }

        $validated = $request->validate([
            'reason' => ['nullable', Rule::in(self::UNSUBSCRIBE_REASONS)],
            'reason_text' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->renderPage($list, ListPageType::UnsubscribeConfirm, $subscriber, 'public.lists.unsubscribe-confirm', [
            'reason' => $validated['reason'] ?? null,
            'reasonText' => $validated['reason_text'] ?? null,
        ]);
    }

    /** POST unsubscribe/confirm — flips the status through the model (F-1.5-009). */
    public function unsubscribeProcess(Request $request, string $listUid, string $subscriberUid): RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        if (trim((string) $request->input(PublicSubscribeController::HONEYPOT_FIELD, '')) !== '') {
            return redirect()->route('lists.public.unsubscribe.success', [
                'list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid,
            ]);
        }

        $validated = $request->validate([
            'reason' => ['nullable', Rule::in(self::UNSUBSCRIBE_REASONS)],
            'reason_text' => ['nullable', 'string', 'max:500'],
        ]);

        $this->unsubscribe(
            $subscriber,
            (string) $request->ip(),
            $this->composeReason($validated['reason'] ?? null, $validated['reason_text'] ?? null),
        );

        return redirect()->route('lists.public.unsubscribe.success', [
            'list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid,
        ]);
    }

    /** GET unsubscribe success page (F-1.5-009). */
    public function unsubscribeSuccess(string $listUid, string $subscriberUid): Response|RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        if ($subscriber->status !== SubscriberStatus::Unsubscribed) {
            return redirect()->route('lists.public.unsubscribe', [
                'list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid,
            ]);
        }

        return $this->renderPage($list, ListPageType::UnsubscribeSuccess, $subscriber);
    }

    /* ================================================================ *
     *  F-1.5-012 — preference center
     * ================================================================ */

    /** GET preference center — topics + send frequency (F-1.5-012). */
    public function preferencesForm(string $listUid, string $subscriberUid): Response|RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        return $this->renderPage($list, ListPageType::PreferenceCenter, $subscriber, 'public.lists.preferences', [
            'topics' => $this->preferences->topics($list, $subscriber),
            'frequencies' => SendFrequency::options(),
            'frequency' => ($subscriber->send_frequency ?? SendFrequency::EverySend)->value,
            'pauseDays' => $this->remainingPauseDays($subscriber),
        ]);
    }

    /**
     * POST preference center — store the topic set and the send frequency
     * (F-1.5-012). Topic changes are membership changes, so they run through
     * the model (cascades) and land in the §4.5 consent audit trail.
     */
    public function preferencesSave(Request $request, string $listUid, string $subscriberUid): RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        // Honeypot (F-1.5-016): pretend success, change nothing.
        if (trim((string) $request->input(PublicSubscribeController::HONEYPOT_FIELD, '')) !== '') {
            return back()->with('public_status', 'preferences_saved');
        }

        if (($captchaError = $this->verifyCaptcha($request)) !== null) {
            return back()->withErrors(['captcha' => $captchaError])->withInput();
        }

        $validated = $request->validate([
            'topics' => ['nullable', 'array'],
            'topics.*' => ['string', 'max:64'],
            'frequency' => ['required', Rule::in(array_column(SendFrequency::cases(), 'value'))],
            'pause_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $frequency = SubscriberPreferences::frequency($validated['frequency']);

        if ($frequency->isPause() && ($validated['pause_days'] ?? null) === null) {
            return back()->withErrors(['pause_days' => __('public.lists.pause_days_required')])->withInput();
        }

        $this->preferences->applyTopics(
            $list,
            $subscriber,
            array_values(array_map('strval', $validated['topics'] ?? [])),
            (string) $request->ip(),
        );

        // Re-read: applyTopics may have flipped this very row's status.
        $this->preferences->saveFrequency($subscriber->refresh(), $frequency, $validated['pause_days'] ?? null);

        return back()->with('public_status', 'preferences_saved');
    }

    /* ================================================================ *
     *  F-1.5-013 — resubscribe
     * ================================================================ */

    /** GET resubscribe page — reached from the subscriber's own uid link. */
    public function resubscribeForm(string $listUid, string $subscriberUid): Response|RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        // Already a member: nothing to resubscribe — show where they stand.
        if ($subscriber->status->isActive()) {
            return redirect()->route(
                $subscriber->status === SubscriberStatus::Unconfirmed
                    ? 'lists.public.pending'
                    : 'lists.public.subscribed',
                ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid],
            );
        }

        return $this->renderPage($list, ListPageType::Resubscribe, $subscriber, 'public.lists.resubscribe', [
            'blocked' => $this->preferences->blocked($list, (string) $subscriber->email, $subscriber),
        ]);
    }

    /**
     * POST resubscribe — back to unconfirmed + a fresh confirmation email on
     * a double opt-in list, confirmed on a single opt-in list, with a new
     * subscribe IP + timestamp (F-4.5-004). The subscriber uid in the URL is
     * the only identity input, so this can never re-add somebody else.
     */
    public function resubscribeProcess(Request $request, string $listUid, string $subscriberUid): RedirectResponse
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        if (trim((string) $request->input(PublicSubscribeController::HONEYPOT_FIELD, '')) !== '') {
            return back()->with('public_status', 'resubscribed');
        }

        if (($captchaError = $this->verifyCaptcha($request)) !== null) {
            return back()->withErrors(['captcha' => $captchaError])->withInput();
        }

        if ($subscriber->status->isActive()) {
            return redirect()->route(
                $subscriber->status === SubscriberStatus::Unconfirmed
                    ? 'lists.public.pending'
                    : 'lists.public.subscribed',
                ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid],
            );
        }

        // D-74: a blacklisted address is never resubscribed from a public page.
        if ($this->preferences->blocked($list, (string) $subscriber->email, $subscriber)) {
            return back()->withErrors(['email' => __('public.lists.resubscribe_blocked')]);
        }

        $status = $this->preferences->resubscribe($list, $subscriber, (string) $request->ip());

        return redirect()->route(
            $status === SubscriberStatus::Unconfirmed ? 'lists.public.pending' : 'lists.public.subscribed',
            ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid],
        );
    }

    /** Whole days left on an active pause, for the form's prefill. */
    private function remainingPauseDays(Subscriber $subscriber): ?int
    {
        $until = $subscriber->frequency_paused_until;

        if ($until === null || $until->isPast()) {
            return null;
        }

        return max(1, (int) ceil(now()->diffInDays($until, false)));
    }

    /**
     * POST unsubscribe-one-click — RFC 8058 List-Unsubscribe-Post target
     * (F-1.5-015). CSRF-exempt at the route (mail clients POST directly,
     * body `List-Unsubscribe=One-Click`), no captcha/honeypot (machine
     * endpoint), unsubscribes immediately and answers 200.
     */
    public function oneClickUnsubscribe(Request $request, string $listUid, string $subscriberUid): Response
    {
        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        $this->unsubscribe($subscriber, (string) $request->ip(), 'one-click');

        return response(__('public.lists.one_click_done'), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /** Idempotent status flip through the model so cascades fire. */
    private function unsubscribe(Subscriber $subscriber, string $ip, ?string $reason): void
    {
        if ($subscriber->status === SubscriberStatus::Unsubscribed) {
            return;
        }

        $subscriber->status = SubscriberStatus::Unsubscribed;
        // WP-22 (§3.25.16 F-3.25.16-005): anonymise at capture.
        $subscriber->unsubscribe_ip = IpAnonymizer::maybe($ip);

        if ($reason !== null && $reason !== '') {
            $subscriber->unsubscribe_reason = mb_substr($reason, 0, 191);
        }

        $subscriber->save();
    }

    private function composeReason(?string $reason, ?string $text): ?string
    {
        $parts = array_filter([
            $reason,
            $text !== null ? trim($text) : null,
        ], fn (?string $v): bool => $v !== null && $v !== '');

        return $parts === [] ? null : implode(': ', $parts);
    }

    /**
     * Render a page type honoring the per-page redirect override
     * (F-2.2.12-005) and — for flow-completion pages — the list-wide
     * Redirect page URL (F-1.5-010).
     *
     * @param  array<string, mixed>  $data
     */
    private function renderPage(EmailList $list, ListPageType $type, ?Subscriber $subscriber = null, string $view = 'public.lists.page', array $data = []): Response|RedirectResponse
    {
        $override = $this->resolver->redirectUrl($list, $type);

        if ($override !== null) {
            return redirect()->away($override);
        }

        $flowCompletion = in_array($type, [
            ListPageType::SubscribePending,
            ListPageType::SubscribeConfirm,
            ListPageType::UnsubscribeSuccess,
        ], true);

        if ($flowCompletion) {
            $global = $this->resolver->redirectUrl($list, ListPageType::Redirect);

            if ($global !== null) {
                return redirect()->away($global);
            }
        }

        return response()->view($view, array_merge([
            'list' => $list,
            'subscriber' => $subscriber,
            'title' => $list->name,
            'content' => $this->renderer->html($list, $type, $subscriber),
            'footerHtml' => $this->renderer->footer($list),
            'customCss' => $this->renderer->safeCss($this->resolver->customCss($list, $type)),
            'customJs' => $this->renderer->safeJs($this->resolver->customJs($list, $type)),
        ], $data));
    }

    /** Ordered fields carrying one of the visibility flags. */
    private function formFields(EmailList $list, string $visibilityColumn): array
    {
        return ListField::withoutTenancy()
            ->where('list_id', $list->id)
            ->where($visibilityColumn, true)
            ->with('options')
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    /** Returns an error message when the configured captcha fails (F-1.5-016). */
    private function verifyCaptcha(Request $request): ?string
    {
        $captcha = CaptchaManager::driver();

        if ($captcha->enabled()
            && ! $captcha->verify($request->input($captcha->fieldName()), $request->ip())) {
            return __('lists.embed.captcha_failed');
        }

        return null;
    }
}
