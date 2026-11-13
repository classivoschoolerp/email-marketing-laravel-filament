<?php

declare(strict_types=1);

namespace App\Events\Campaign;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * HOOK `campaign.on_open` (spec §6.1 F-6.1-012).
 *
 * Fired from App\Http\Controllers\Public\TrackingController::open() after the
 * campaign_track_opens row is written, on the tracking-pixel request itself.
 *
 * Carries UIDs, not models: the tracking endpoints are the hottest path in
 * the app and deliberately never load Campaign/Subscriber (see the class
 * docblock there). Listeners that need models must look them up — and should
 * queue that work rather than doing it in the pixel response.
 *
 * `$ip` is already anonymised when the §3.25.16 privacy toggle is on.
 */
class CampaignOpened
{
    use Dispatchable;

    public function __construct(
        public readonly string $campaignUid,
        public readonly string $subscriberUid,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}
}
