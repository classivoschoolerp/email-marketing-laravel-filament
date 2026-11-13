<?php

declare(strict_types=1);

namespace App\Events\Campaign;

use App\Enums\BounceType;
use App\Models\CampaignBounceLog;
use App\Models\Subscriber;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HOOK `campaign.on_bounce` (spec §6.1 F-6.1-012).
 *
 * Fired from App\Services\Bounces\BounceProcessor once a polled DSN has been
 * parsed, classified and attributed, the subscriber actions have been applied
 * and the campaign_bounce_logs row exists. Runs on the bounce-polling worker,
 * never in a web request.
 *
 * $subscriber is null for a bounce that could not be attributed to anyone,
 * and $type is BounceType::Internal for messages the classifier refused to
 * hold a subscriber responsible for (D-65).
 */
class CampaignBounced
{
    use Dispatchable;

    public function __construct(
        public readonly CampaignBounceLog $log,
        public readonly BounceType $type,
        public readonly ?Subscriber $subscriber = null,
        public readonly ?int $campaignId = null,
    ) {}
}
