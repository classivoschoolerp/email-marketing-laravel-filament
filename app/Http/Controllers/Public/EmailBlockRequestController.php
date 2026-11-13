<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\BlockRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Mail\BlockRequestConfirmMail;
use App\Models\EmailBlockRequest;
use App\Services\Captcha\CaptchaManager;
use App\Services\EmailBlocks\BlockRequestApprover;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Public "never email me again" flow (spec §1.10): form → pending request +
 * signed confirmation email → confirmed (→ auto-approved into the global
 * blacklist when config('emp-public.block_requests.auto_approve') is on).
 * Admin review lives in EmailBlockRequestResource (§3.19).
 */
class EmailBlockRequestController extends Controller
{
    /** GET /email-block-request — the public form (F-1.10-001). */
    public function form(): Response
    {
        return response()->view('public.block-request');
    }

    /** POST /email-block-request — create the pending request (F-1.10-001/002). */
    public function submit(Request $request): RedirectResponse
    {
        // Honeypot (F-1.5-016 pattern): pretend success, store nothing.
        if (trim((string) $request->input(PublicSubscribeController::HONEYPOT_FIELD, '')) !== '') {
            return back()->with('public_status', 'block_request_sent');
        }

        $captcha = CaptchaManager::driver();

        if ($captcha->enabled()
            && ! $captcha->verify($request->input($captcha->fieldName()), $request->ip())) {
            return back()->withErrors(['captcha' => __('lists.embed.captcha_failed')])->withInput();
        }

        $validated = $request->validate(['email' => ['required', 'email', 'max:191']]);

        $email = mb_strtolower(trim((string) $validated['email']));

        // Re-submitting re-uses the open request (and re-sends the link)
        // instead of piling up duplicates in the admin queue.
        $blockRequest = EmailBlockRequest::query()
            ->where('email', $email)
            ->whereIn('status', [BlockRequestStatus::Pending->value, BlockRequestStatus::Confirmed->value])
            ->first();

        $blockRequest ??= EmailBlockRequest::create([
            'email' => $email,
            'status' => BlockRequestStatus::Pending,
            'ip' => (string) $request->ip(),
        ]);

        Mail::to($email)->queue(new BlockRequestConfirmMail($this->confirmUrl($blockRequest)));

        return back()->with('public_status', 'block_request_sent');
    }

    /**
     * GET /email-block-request/{uid}/confirm — signed link from the email
     * (F-1.10-002). Confirms the request; auto-approves per config.
     */
    public function confirm(string $uid, BlockRequestApprover $approver): Response
    {
        /** @var EmailBlockRequest|null $blockRequest */
        $blockRequest = EmailBlockRequest::query()->where('uid', $uid)->first();

        abort_if($blockRequest === null, 404);

        if ($blockRequest->status === BlockRequestStatus::Pending) {
            $blockRequest->status = BlockRequestStatus::Confirmed;
            $blockRequest->confirmed_at = now();
            $blockRequest->save();
        }

        // Auto-approve setting (F-3.19-003) — see config/emp-public.php.
        if (config('emp-public.block_requests.auto_approve')
            && $blockRequest->status === BlockRequestStatus::Confirmed) {
            $approver->approve($blockRequest);
        }

        return response()->view('public.block-request-confirmed', [
            'blockRequest' => $blockRequest,
        ]);
    }

    /** Signed, expiring confirm URL (F-1.10-002). */
    private function confirmUrl(EmailBlockRequest $blockRequest): string
    {
        return URL::temporarySignedRoute(
            'block_request.confirm',
            now()->addHours((int) config('emp-public.block_requests.confirm_ttl_hours', 48)),
            ['uid' => $blockRequest->uid],
        );
    }
}
