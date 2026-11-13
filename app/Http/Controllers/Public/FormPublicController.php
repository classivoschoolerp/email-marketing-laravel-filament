<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVariant;
use App\Services\Captcha\CaptchaManager;
use App\Services\Forms\FormAnalytics;
use App\Services\Forms\FormEmbedGenerator;
use App\Services\Forms\FormRenderer;
use App\Services\Forms\FormStyles;
use App\Services\Forms\FormSubmissionService;
use App\Services\Forms\FormVariantAssigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Public surface of the §2.7 forms module.
 *
 * Access model: the form `uid` is the key (MailWizz pattern) and only an
 * ACTIVE form answers — a snippet left on a customer's site stops rendering
 * the moment the form is paused, and a uid belonging to another tenant is
 * simply a 404 because nothing here ever reads the session's tenant.
 *
 * The three machine endpoints (impression beacon, per-step validation, submit)
 * are CSRF-exempt at the route: they are called from third-party origins that
 * cannot carry a session token. Honeypot + captcha + the route rate limit are
 * the abuse controls, exactly as for the §2.2.11 embed endpoint.
 *
 * A/B assignment (F-2.7-013) is a COOKIE, never a session value — D-95 holds
 * here as everywhere else on this controller. The rendered arm is additionally
 * carried in the form body ({@see FormRenderer::VARIANT_FIELD}) because the
 * loader posts with `credentials: "omit"` from someone else's origin, so no
 * cookie reaches the submit endpoint at all.
 */
class FormPublicController extends Controller
{
    public function __construct(
        private readonly FormEmbedGenerator $embeds,
        private readonly FormRenderer $renderer,
        private readonly FormSubmissionService $submissions,
        private readonly FormAnalytics $analytics,
        private readonly FormVariantAssigner $variants,
    ) {}

    /**
     * GET — the self-contained embed loader (F-2.7-011).
     *
     * Note the arm is drawn HERE, on the script request, so the markup the
     * loader injects already belongs to one arm. The response is cacheable for
     * five minutes, which is a per-visitor cache of that assignment; the cookie
     * carries it further where the browser accepts one.
     */
    public function script(Request $request, string $uid): Response
    {
        $form = $this->resolve($uid);
        $variant = $this->variants->assign($form, $request);

        $body = view('public.forms.loader', [
            'payload' => json_encode(
                $this->embeds->payload($form, $variant),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        ])->render();

        return response($body, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /** GET — the hosted page, also the target of the iframe variant. */
    public function show(Request $request, string $uid): Response
    {
        $form = $this->resolve($uid);
        $variant = $this->variants->assign($form, $request);

        $this->analytics->recordView($form, $request->headers->get('referer'), $variant);

        return response()->view('public.forms.show', [
            'form' => $form,
            'css' => FormStyles::css($form, $variant),
            'scope' => FormStyles::scope($form),
            'body' => $this->renderer->body($form, $variant),
            'action' => route('forms.public.submit', ['uid' => $form->uid]),
            'stepUrl' => route('forms.public.step', ['uid' => $form->uid]),
            'formErrors' => [],
        ]);
    }

    /** POST — impression beacon from the loader (F-2.7-012). */
    public function impression(Request $request, string $uid): Response
    {
        $form = $this->resolve($uid);

        $this->analytics->recordView(
            $form,
            (string) ($request->input('source') ?: $request->headers->get('referer')),
            $this->resolveVariant($request, $form),
        );

        return response()->noContent()->withHeaders(['Access-Control-Allow-Origin' => '*']);
    }

    /**
     * POST — per-step validation for the "Next" button (F-2.7-004).
     *
     * Writes nothing. It exists so a bad value on step 1 is reported while the
     * visitor is still looking at step 1; {@see FormSubmissionService::submit()}
     * re-validates every field of the form, so a client that never calls this
     * endpoint — or lies about which step it is on — gains nothing.
     */
    public function step(Request $request, string $uid): JsonResponse
    {
        $form = $this->resolve($uid);

        try {
            $this->submissions->validateStep(
                $form,
                (int) $request->input(FormRenderer::STEP_FIELD, 1),
                $request->all(),
                $this->resolveVariant($request, $form),
            );
        } catch (ValidationException $exception) {
            return response()->json(['ok' => false, 'errors' => $exception->errors()], 422)
                ->withHeaders(['Access-Control-Allow-Origin' => '*']);
        }

        return response()->json(['ok' => true])
            ->withHeaders(['Access-Control-Allow-Origin' => '*']);
    }

    /** POST — the submission endpoint (F-2.7-003/008/009/010). */
    public function submit(Request $request, string $uid): JsonResponse|RedirectResponse|Response
    {
        $form = $this->resolve($uid);
        $variant = $this->resolveVariant($request, $form);

        // Honeypot (F-2.7-010): a filled hidden field marks a bot. Answer
        // success so it learns nothing, and create nothing.
        if (trim((string) $request->input(PublicSubscribeController::HONEYPOT_FIELD, '')) !== '') {
            return $this->success($request, $form, null);
        }

        if ($form->captcha_enabled) {
            $captcha = CaptchaManager::driver();

            if ($captcha->enabled()
                && ! $captcha->verify($request->input($captcha->fieldName()), $request->ip())) {
                return $this->failure($request, $form, ['captcha' => [__('lists.embed.captcha_failed')]], $variant);
            }
        }

        try {
            $submission = $this->submissions->submit($form, $request->all(), $request, $variant);
        } catch (ValidationException $exception) {
            return $this->failure($request, $form, $exception->errors(), $variant);
        }

        return $this->success($request, $form, $submission);
    }

    /**
     * GET — the lead-magnet download (F-2.7-008). The link is signed at
     * submit time and short-lived, so the asset is not a public URL that can
     * be shared around the form.
     */
    public function download(string $uid, string $submissionUid): RedirectResponse
    {
        $form = $this->resolve($uid);

        $submission = FormSubmission::withoutTenancy()
            ->where('form_id', $form->id)
            ->where('uid', $submissionUid)
            ->first();

        abort_if($submission === null, 404);
        abort_if(trim((string) $form->lead_magnet_url) === '', 404);

        return redirect()->away((string) $form->lead_magnet_url);
    }

    /** CORS preflight for the two machine endpoints. */
    public function preflight(): Response
    {
        return response('', 204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Accept, Content-Type',
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    /** Public resolution: uid + active only, tenancy never consulted. */
    private function resolve(string $uid): Form
    {
        /** @var Form|null $form */
        $form = Form::withoutTenancy()->where('uid', $uid)->first();

        abort_if($form === null || ! $form->isPublic(), 404);

        return $form;
    }

    /**
     * The A/B arm this request belongs to (F-2.7-013).
     *
     * The uid posted with the form wins over the cookie: it names the arm that
     * was actually RENDERED for this visitor, and it is the only signal that
     * survives the loader's cross-origin `credentials: "omit"` submit. It is
     * matched against the form's own enabled arms, so a forged value can only
     * ever name a real arm of this same form.
     */
    private function resolveVariant(Request $request, Form $form): ?FormVariant
    {
        $uid = trim((string) $request->input(FormRenderer::VARIANT_FIELD, ''));

        if ($uid !== '') {
            foreach ($form->publicVariants() as $variant) {
                if ($variant->uid === $uid) {
                    return $variant;
                }
            }
        }

        return $this->variants->current($form, $request);
    }

    /** @param array<string, list<string>> $errors */
    private function failure(Request $request, Form $form, array $errors, ?FormVariant $variant = null): JsonResponse|Response
    {
        if ($this->wantsJson($request)) {
            return response()->json(['ok' => false, 'errors' => $errors], 422)
                ->withHeaders(['Access-Control-Allow-Origin' => '*']);
        }

        return response()->view('public.forms.show', [
            'form' => $form,
            'css' => FormStyles::css($form, $variant),
            'scope' => FormStyles::scope($form),
            'body' => $this->renderer->body($form, $variant),
            'action' => route('forms.public.submit', ['uid' => $form->uid]),
            'stepUrl' => route('forms.public.step', ['uid' => $form->uid]),
            'formErrors' => $errors,
        ], 422);
    }

    private function success(Request $request, Form $form, ?FormSubmission $submission): JsonResponse|RedirectResponse|Response
    {
        $message = trim((string) $form->success_message) ?: __('forms.public.success_default');
        $mode = (string) $form->success_mode;
        $redirect = trim((string) $form->redirect_url);
        $download = null;

        if ($mode === Form::SUCCESS_DOWNLOAD && $submission !== null && trim((string) $form->lead_magnet_url) !== '') {
            $download = URL::temporarySignedRoute('forms.public.download', now()->addHours(24), [
                'uid' => $form->uid,
                'submission' => $submission->uid,
            ]);
        }

        if ($this->wantsJson($request)) {
            return response()->json([
                'ok' => true,
                'mode' => $mode,
                'message' => $message,
                'redirect' => $mode === Form::SUCCESS_REDIRECT && $redirect !== '' ? $redirect : null,
                'download' => $download,
            ])->withHeaders(['Access-Control-Allow-Origin' => '*']);
        }

        if ($mode === Form::SUCCESS_REDIRECT && $redirect !== '') {
            return redirect()->away($redirect);
        }

        if ($download !== null) {
            return redirect()->away($download);
        }

        return response()->view('public.forms.thanks', [
            'form' => $form,
            'css' => FormStyles::css($form),
            'scope' => FormStyles::scope($form),
            'message' => $message,
        ]);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }
}
