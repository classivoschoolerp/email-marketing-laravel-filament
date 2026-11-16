<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automations;

use App\Models\AutomationEmailSend;
use App\Services\Automations\TrackSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * WP-21 open-pixel / click-redirect endpoints for automation emails
 * (§2.4.3 email performance). HMAC-signed per send row; click signatures
 * cover the destination URL (no open redirect). First-touch timestamps
 * only — automation reports need opened/clicked flags, not event streams.
 */
class AutomationTrackingController
{
    public function open(string $uid, string $sig, TrackSigner $signer): Response
    {
        if ($signer->validOpen($uid, $sig)) {
            $send = AutomationEmailSend::withoutTenancy()->where('uid', $uid)->first();

            if ($send !== null && $send->opened_at === null) {
                $send->forceFill(['opened_at' => Carbon::now()])->save();
            }
        }

        // 1x1 transparent GIF regardless of validity.
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($pixel, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, string $uid, string $sig, TrackSigner $signer): RedirectResponse
    {
        $url = (string) $request->query('u', '');

        abort_unless($url !== '' && $signer->validClick($uid, $url, $sig), 404);

        $send = AutomationEmailSend::withoutTenancy()->where('uid', $uid)->first();

        if ($send !== null) {
            $send->forceFill([
                'clicked_at' => $send->clicked_at ?? Carbon::now(),
                'opened_at' => $send->opened_at ?? Carbon::now(), // click implies open
                'last_click_url' => mb_substr($url, 0, 2048),
            ])->save();
        }

        return redirect()->away($url);
    }
}
