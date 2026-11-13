<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Http\Controllers\Public\Concerns\ResolvesPublicList;
use App\Mail\ErasureRequestConfirmMail;
use App\Models\ErasureRequest;
use App\Models\Subscriber;
use App\Services\Captcha\CaptchaManager;
use App\Services\Lists\FieldValueService;
use App\Services\Settings\SettingsReader;
use App\Support\Privacy\IpAnonymizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Public compliance surface (spec §4.5 + §3.25.16, WP-22):
 *
 *  - GDPR portability (F-4.5-001): a subscriber downloads everything stored
 *    about them as JSON from their own update-profile page. The link is a
 *    temporary signed URL (short TTL) AND rate-limited at the route, so the
 *    uid alone is not enough to scrape data at volume.
 *  - Right to erasure (F-4.5-001 / F-3.25.16-003): request → signed email
 *    confirmation → admin queue → {@see \App\Services\Privacy\ErasureProcessor}
 *    which reuses the existing GdprEraseSubscriberJob (D-31). Every endpoint
 *    404s when the workflow toggle is off.
 *  - DPA text (F-3.25.16-004) + sub-processor list (F-4.5-001) as public
 *    /legal pages.
 *  - Cookie consent choice storage (F-3.25.16-001 / F-4.5-006); the banner
 *    itself renders from the public layout.
 */
class PrivacyController extends Controller
{
    use ResolvesPublicList;

    public function __construct(private readonly FieldValueService $fieldValues) {}

    // ------------------------------------------------ GDPR data portability

    /**
     * GET /privacy/lists/{list_uid}/{subscriber_uid}/export — signed link
     * from the update-profile page (F-4.5-001, portability).
     */
    public function exportData(string $listUid, string $subscriberUid): JsonResponse
    {
        abort_unless(SettingsReader::privacy()->dataExportEnabled(), 404);

        $list = $this->resolvePublicList($listUid);
        $subscriber = $this->resolvePublicSubscriber($list, $subscriberUid);

        return response()
            ->json($this->exportPayload($subscriber), 200, [
                'Content-Disposition' => 'attachment; filename="subscriber-'.$subscriber->uid.'.json"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Everything the platform stores about one subscription — the same
     * shape as the customer-panel export (F-2.2.5-010) plus the §4.5
     * consent proof block, so the two exports never diverge.
     *
     * @return array<string, mixed>
     */
    public function exportPayload(Subscriber $subscriber): array
    {
        $list = $subscriber->list()->withoutGlobalScopes()->first();

        return [
            'uid' => $subscriber->uid,
            'email' => $subscriber->email,
            'list' => $list?->name,
            'status' => $subscriber->status->value,
            'source' => $subscriber->source?->value,
            'ip' => $subscriber->ip,
            'subscribed_at' => $subscriber->subscribed_at?->toIso8601String(),
            'subscribe_ip' => $subscriber->subscribe_ip,
            'confirmed_at' => $subscriber->confirmed_at?->toIso8601String(),
            'confirm_ip' => $subscriber->confirm_ip,
            'unsubscribed_at' => $subscriber->unsubscribed_at?->toIso8601String(),
            'unsubscribe_ip' => $subscriber->unsubscribe_ip,
            'unsubscribe_reason' => $subscriber->unsubscribe_reason,
            'fields' => collect($this->fieldValues->byTag($subscriber))
                ->map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface
                    ? $value->format('Y-m-d H:i:s')
                    : $value)
                ->all(),
            // GDPR consent proof (F-4.5-001): what was consented to, when,
            // from where — plus the audit trail of every later change.
            'consent' => [
                'text' => $list?->gdpr_consent_text,
                'granted_at' => $subscriber->confirmed_at?->toIso8601String()
                    ?? $subscriber->subscribed_at?->toIso8601String(),
                'granted_ip' => $subscriber->confirm_ip ?? $subscriber->subscribe_ip ?? $subscriber->ip,
                'changes' => \App\Models\ConsentEvent::withoutTenancy()
                    ->where('subscriber_id', $subscriber->id)
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($event): array => [
                        'field' => $event->field,
                        'from' => $event->old_value,
                        'to' => $event->new_value,
                        'ip' => $event->ip,
                        'source' => $event->source,
                        'at' => $event->created_at?->toIso8601String(),
                    ])->all(),
            ],
        ];
    }

    /** Short-lived signed download URL for the update-profile page. */
    public static function exportUrl(Subscriber $subscriber, string $listUid): string
    {
        return URL::temporarySignedRoute(
            'privacy.export',
            now()->addMinutes((int) config('emp-privacy.export.link_ttl_minutes', 60)),
            ['list_uid' => $listUid, 'subscriber_uid' => $subscriber->uid],
        );
    }

    // ----------------------------------------------------- Right to erasure

    /** GET /privacy/erasure — the public request form (F-3.25.16-003). */
    public function erasureForm(): Response
    {
        abort_unless(SettingsReader::privacy()->erasureWorkflowEnabled(), 404);

        return response()->view('public.privacy.erasure');
    }

    /** POST /privacy/erasure — create the pending request + confirm email. */
    public function erasureSubmit(Request $request): RedirectResponse
    {
        abort_unless(SettingsReader::privacy()->erasureWorkflowEnabled(), 404);

        // Honeypot (F-1.5-016 pattern): pretend success, store nothing.
        if (trim((string) $request->input(PublicSubscribeController::HONEYPOT_FIELD, '')) !== '') {
            return back()->with('public_status', 'erasure_requested');
        }

        $captcha = CaptchaManager::driver();

        if ($captcha->enabled()
            && ! $captcha->verify($request->input($captcha->fieldName()), $request->ip())) {
            return back()->withErrors(['captcha' => __('lists.embed.captcha_failed')])->withInput();
        }

        $validated = $request->validate(['email' => ['required', 'email', 'max:191']]);
        $email = mb_strtolower(trim((string) $validated['email']));

        // Re-submitting reuses the open request instead of piling duplicates
        // into the admin queue (the §1.10 block-request precedent).
        $erasure = ErasureRequest::query()
            ->where('email', $email)
            ->whereIn('status', [ErasureRequest::STATUS_PENDING, ErasureRequest::STATUS_CONFIRMED])
            ->first();

        $erasure ??= ErasureRequest::create([
            'email' => $email,
            'status' => ErasureRequest::STATUS_PENDING,
            'ip' => IpAnonymizer::maybe((string) $request->ip()),
        ]);

        $hours = (int) config('emp-privacy.erasure.confirm_ttl_hours', 48);

        Mail::to($email)->queue(new ErasureRequestConfirmMail($this->confirmUrl($erasure, $hours), $hours));

        return back()->with('public_status', 'erasure_requested');
    }

    /** GET /privacy/erasure/{uid}/confirm — signed link from the email. */
    public function erasureConfirm(string $uid): Response
    {
        abort_unless(SettingsReader::privacy()->erasureWorkflowEnabled(), 404);

        /** @var ErasureRequest|null $erasure */
        $erasure = ErasureRequest::query()->where('uid', $uid)->first();

        abort_if($erasure === null, 404);

        if ($erasure->status === ErasureRequest::STATUS_PENDING) {
            $erasure->forceFill([
                'status' => ErasureRequest::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ])->save();
        }

        return response()->view('public.privacy.erasure-confirmed', ['erasureRequest' => $erasure]);
    }

    private function confirmUrl(ErasureRequest $erasure, int $hours): string
    {
        return URL::temporarySignedRoute(
            'privacy.erasure.confirm',
            now()->addHours($hours),
            ['uid' => $erasure->uid],
        );
    }

    // --------------------------------------------------------- Legal pages

    /** GET /legal/dpa — the data processing agreement (F-3.25.16-004). */
    public function dpa(): Response
    {
        return response()->view('public.privacy.legal', [
            'title' => __('privacy.legal.dpa_title'),
            'body' => SettingsReader::privacy()->dpaText(),
            'missing' => __('privacy.legal.dpa_missing'),
            'intro' => null,
        ]);
    }

    /** GET /legal/sub-processors — the sub-processor list (F-4.5-001). */
    public function subProcessors(): Response
    {
        return response()->view('public.privacy.legal', [
            'title' => __('privacy.legal.sub_processors_title'),
            'body' => SettingsReader::privacy()->subProcessorsText(),
            'missing' => __('privacy.legal.sub_processors_missing'),
            'intro' => __('privacy.legal.sub_processors_intro'),
        ]);
    }

    // ------------------------------------------------------- Cookie consent

    /**
     * POST /privacy/cookie-consent — stores the visitor's banner choice in a
     * first-party cookie (F-3.25.16-001 / F-4.5-006). No personal data is
     * recorded: the choice IS the record.
     */
    public function cookieConsent(Request $request): JsonResponse|RedirectResponse
    {
        $choice = $request->input('choice') === 'accepted' ? 'accepted' : 'declined';

        $cookie = Cookie::create(
            (string) config('emp-privacy.cookies.cookie_name', 'emp_cookie_consent'),
            $choice,
            now()->addDays((int) config('emp-privacy.cookies.cookie_days', 365))->getTimestamp(),
            '/',
            null,
            $request->isSecure(),
            false, // readable by the banner script
            false,
            'lax',
        );

        if ($request->expectsJson()) {
            return response()->json(['choice' => $choice])->withCookie($cookie);
        }

        return back()->withCookie($cookie);
    }
}
