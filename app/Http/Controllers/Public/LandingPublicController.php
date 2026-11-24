<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Models\LandingPage;
use App\Models\Subscriber;
use App\Services\Captcha\CaptchaManager;
use App\Services\Forms\FormSubmissionService;
use App\Services\Landing\LandingCustomCode;
use App\Services\Landing\LandingPageAccess;
use App\Services\Landing\LandingPageRenderer;
use App\Services\Landing\LandingPageTracking;
use App\Services\Landing\LandingPageUrl;
use App\Services\Landing\LandingStyles;
use App\Services\Landing\LandingVariantAssigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Public surface of the landing-page module (spec §1.8).
 *
 * Access model, identical in spirit to §2.7 forms: the `slug` is the key, the
 * session is NEVER consulted, and only a LIVE page answers — a draft, an
 * unpublished page or one scheduled for the future is a plain 404 so a link
 * shared early leaks nothing.
 *
 * Two fences beyond that:
 *  - HOST: a page answers on the app host and on its own assigned custom
 *    domain only. Slugs are globally unique, so without this check tenant A's
 *    page would render on tenant B's domain — see
 *    {@see LandingPageUrl::servableOnHost()}.
 *  - The submit endpoint is CSRF-exempt at the route, because a page served
 *    on a customer's own domain cannot carry the app's session cookie. The
 *    honeypot, the configured captcha and the route rate limit are the abuse
 *    controls — exactly the §2.7 form-submit trade-off, for exactly the same
 *    reason.
 */
class LandingPublicController extends Controller
{
    public function __construct(
        private readonly LandingPageRenderer $renderer,
        private readonly LandingVariantAssigner $variants,
        private readonly LandingPageTracking $tracking,
        private readonly LandingPageUrl $urls,
        private readonly LandingCustomCode $code,
        private readonly FormSubmissionService $submissions,
        private readonly LandingPageAccess $access,
    ) {}

    /** GET — render the page, serve the A/B arm, count the visit. */
    public function show(Request $request, string $slug): Response
    {
        $page = $this->resolve($request, $slug);

        // F-1.8-006: a gated page renders the prompt and nothing else — no
        // content, and no visit, because nobody has seen the page yet.
        if (! $this->access->unlocked($page, $request)) {
            return $this->passwordPrompt($page);
        }

        $variant = $this->variants->assign($page, $request);
        $visitorKey = $this->tracking->visitorKey($request);

        $this->tracking->recordVisit($page, $variant, $request, $visitorKey);

        return response()->view('public.landing.show', [
            'page' => $page,
            'doc' => $this->renderer->document($page, $variant, $page->publicForm()),
        ]);
    }

    /** POST — the embedded form's submission (F-1.8-004, F-2.6-009/011). */
    public function submit(Request $request, string $slug): JsonResponse|RedirectResponse|Response
    {
        $page = $this->resolve($request, $slug);

        // F-1.8-006: the submit endpoint is gated too. Without this a bot
        // could harvest (or subscribe through) a page it never unlocked.
        abort_unless($this->access->unlocked($page, $request), 403);

        $form = $page->publicForm();

        abort_if($form === null || ! $form->isPublic(), 404);

        // Honeypot: answer success so a bot learns nothing, create nothing —
        // and record no conversion, because none happened.
        if (trim((string) $request->input(PublicSubscribeController::HONEYPOT_FIELD, '')) !== '') {
            return $this->success($request, $page, null);
        }

        if ($form->captcha_enabled) {
            $captcha = CaptchaManager::driver();

            if ($captcha->enabled()
                && ! $captcha->verify($request->input($captcha->fieldName()), $request->ip())) {
                return $this->failure($request, $page, ['captcha' => [__('lists.embed.captcha_failed')]]);
            }
        }

        try {
            // The §2.7 write path, unchanged: it creates the subscriber
            // THROUGH the model (so the list cascades and the §1.5 lifecycle
            // emails fire) and raises `form_submitted` on the automation bus
            // with the form id — F-2.4.2-011 behaves identically whether the
            // visitor used the hosted form or this landing page.
            $submission = $this->submissions->submit($form, $request->all(), $request);
        } catch (ValidationException $exception) {
            return $this->failure($request, $page, $exception->errors());
        }

        $this->tracking->recordConversion(
            $page,
            $this->variants->current($page, $request),
            $request,
            $this->tracking->visitorKey($request),
            $submission->subscriber_id === null
                ? null
                : Subscriber::withoutTenancy()->find($submission->subscriber_id),
            $submission,
        );

        return $this->success($request, $page, $submission->id);
    }

    /** GET — the dedicated thank-you page (F-2.6-011). */
    public function thanks(Request $request, string $slug): Response
    {
        $page = $this->resolve($request, $slug);

        if (! $this->access->unlocked($page, $request)) {
            return $this->passwordPrompt($page);
        }

        return response()->view('public.landing.thanks', $this->thanksData($page));
    }

    /**
     * POST — the F-1.8-006 unlock form.
     *
     * A wrong password re-renders the prompt with 422 and says only that the
     * password is wrong: the response must not vary by whether the page even
     * has a password, or the endpoint becomes an oracle for page discovery.
     */
    public function unlock(Request $request, string $slug): RedirectResponse|Response
    {
        $page = $this->resolve($request, $slug);

        abort_unless($page->isPasswordProtected(), 404);

        if (! $this->access->attempt($page, $request, (string) $request->input('password', ''))) {
            return $this->passwordPrompt($page, __('landing.public.password_wrong'), 422);
        }

        // POST → redirect → GET so a refresh never replays the password.
        return redirect()->to($this->urls->url($page));
    }

    /** The gate document (F-1.8-006), styled with the page's own CSS. */
    private function passwordPrompt(LandingPage $page, ?string $error = null, int $status = 200): Response
    {
        return response()->view('public.landing.password', [
            'page' => $page,
            'lang' => str_replace('_', '-', app()->getLocale()),
            'title' => $page->displayTitle(),
            'favicon' => trim((string) $page->favicon_url) ?: null,
            'baseCss' => LandingStyles::css(),
            'customCss' => $this->code->css($page),
            'error' => $error,
        ], $status);
    }

    /**
     * Public resolution: live pages only, on a host that may serve them.
     * Tenancy is never consulted.
     */
    private function resolve(Request $request, string $slug): LandingPage
    {
        /** @var LandingPage|null $page */
        $page = LandingPage::withoutTenancy()->where('slug', $slug)->first();

        abort_if($page === null || ! $page->isLive(), 404);
        abort_unless($this->urls->servableOnHost($page, $request->getHost()), 404);

        return $page;
    }

    /** @param array<string, list<string>|string> $errors */
    private function failure(Request $request, LandingPage $page, array $errors): JsonResponse|Response
    {
        if ($this->wantsJson($request)) {
            return response()->json(['ok' => false, 'errors' => $errors], 422);
        }

        return response()->view('public.landing.show', [
            'page' => $page,
            'doc' => $this->renderer->document(
                $page,
                $this->variants->current($page, $request),
                $page->publicForm(),
                $errors,
            ),
        ], 422);
    }

    private function success(Request $request, LandingPage $page, ?int $submissionId): JsonResponse|RedirectResponse|Response
    {
        $mode = (string) $page->after_submit;
        $redirect = trim((string) $page->redirect_url);
        $message = trim((string) $page->success_message) ?: __('landing.public.success_default');

        if ($this->wantsJson($request)) {
            return response()->json([
                'ok' => true,
                'mode' => $mode,
                'message' => $message,
                'redirect' => $mode === LandingPage::AFTER_REDIRECT && $redirect !== '' ? $redirect : null,
                'thank_you' => $mode === LandingPage::AFTER_THANK_YOU ? $this->urls->thanksUrl($page) : null,
                'submission_id' => $submissionId,
            ]);
        }

        if ($mode === LandingPage::AFTER_REDIRECT && $redirect !== '') {
            return redirect()->away($redirect);
        }

        if ($mode === LandingPage::AFTER_THANK_YOU) {
            // POST → redirect → GET, so a refresh cannot resubmit.
            return redirect()->to($this->urls->thanksUrl($page));
        }

        // Message mode shows the MESSAGE. The authored thank-you markup is
        // the thank-you PAGE's content — rendering it here would make the two
        // modes indistinguishable and the setting meaningless.
        return response()->view('public.landing.thanks', $this->thanksData($page, $message, useAuthoredBody: false));
    }

    /** @return array<string, mixed> */
    private function thanksData(LandingPage $page, ?string $message = null, bool $useAuthoredBody = true): array
    {
        $body = $useAuthoredBody ? trim((string) $page->thank_you_html) : '';

        return [
            'page' => $page,
            'lang' => str_replace('_', '-', app()->getLocale()),
            'title' => $page->displayTitle(),
            'favicon' => trim((string) $page->favicon_url) ?: null,
            'baseCss' => LandingStyles::css(),
            'customCss' => $this->code->css($page),
            'body' => $body,
            'message' => $message ?? (trim((string) $page->success_message) ?: __('landing.public.success_default')),
        ];
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }
}
