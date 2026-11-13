<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Services\Campaigns\CampaignForwardService;
use App\Services\Captcha\CaptchaManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Forward to a friend (spec §1.6 F-1.6-002) served at the §1.7 tracker URL
 * /campaigns/{campaign_uid}/track-forward/{subscriber_uid} (F-1.7-004).
 *
 * Same shape as every other public page: no auth (uid is the access secret),
 * honeypot + configured captcha + route throttle, and a "pretend success"
 * honeypot response so a bot learns nothing.
 */
class CampaignForwardController extends Controller
{
    public function __construct(private readonly CampaignForwardService $forwards) {}

    /** GET — the forward form. */
    public function form(string $campaignUid, string $subscriberUid): Response
    {
        $campaign = $this->forwards->campaign($campaignUid);

        abort_if($campaign === null, 404);

        $subscriber = $this->forwards->subscriber($subscriberUid);

        abort_if($subscriber === null, 404);

        return response()->view('public.campaigns.forward', [
            'campaign' => $campaign,
            'subscriber' => $subscriber,
            'maxRecipients' => CampaignForwardService::MAX_RECIPIENTS,
        ]);
    }

    /** POST — record + queue the forwards. */
    public function submit(Request $request, string $campaignUid, string $subscriberUid): RedirectResponse
    {
        $campaign = $this->forwards->campaign($campaignUid);

        abort_if($campaign === null, 404);

        $subscriber = $this->forwards->subscriber($subscriberUid);

        abort_if($subscriber === null, 404);

        // Honeypot (F-1.5-016 pattern): pretend success, store nothing.
        if (trim((string) $request->input(PublicSubscribeController::HONEYPOT_FIELD, '')) !== '') {
            return back()->with('public_status', 'campaign_forwarded');
        }

        $captcha = CaptchaManager::driver();

        if ($captcha->enabled()
            && ! $captcha->verify($request->input($captcha->fieldName()), $request->ip())) {
            return back()->withErrors(['captcha' => __('lists.embed.captcha_failed')])->withInput();
        }

        $validated = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:'.CampaignForwardService::MAX_RECIPIENTS],
            'recipients.*' => ['nullable', 'email', 'max:191'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $recipients = array_values(array_filter(
            array_map(fn ($value): string => trim((string) $value), (array) $validated['recipients']),
            fn (string $value): bool => $value !== '',
        ));

        if ($recipients === []) {
            return back()
                ->withErrors(['recipients' => __('campaigns.forward.no_recipients')])
                ->withInput();
        }

        $this->forwards->forward(
            $campaign,
            $subscriber,
            $recipients,
            $validated['note'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );

        return back()->with('public_status', 'campaign_forwarded');
    }
}
