<?php

declare(strict_types=1);

namespace App\Events\Campaign;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * HOOK `campaign.on_click` (spec §6.1 F-6.1-012).
 *
 * Fired from App\Http\Controllers\Public\TrackingController::click() after
 * the campaign_track_clicks row is written and BEFORE the 302 redirect.
 * The URL has already been HMAC-verified (F-1.7-008), so listeners can trust
 * it is one this installation generated.
 *
 * Listeners must not block: whatever they do delays a real person's redirect.
 */
class CampaignLinkClicked
{
    use Dispatchable;

    public function __construct(
        public readonly string $campaignUid,
        public readonly string $subscriberUid,
        public readonly string $url,
        public readonly ?int $urlId = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}
}
