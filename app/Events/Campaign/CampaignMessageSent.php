<?php

declare(strict_types=1);

namespace App\Events\Campaign;

use App\Models\Campaign;
use App\Models\DeliveryServer;
use App\Models\Subscriber;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HOOK `campaign.post_send` (spec §6.1 F-6.1-012).
 *
 * Fired from App\Jobs\Sending\SendToSubscriberJob once the driver reported a
 * successful hand-off and the delivery log row is finalised as `sent`.
 * "Sent" means accepted by the ESP/relay — NOT delivered to the inbox; the
 * delivery verdict arrives later via bounce processing (`campaign.on_bounce`).
 */
class CampaignMessageSent
{
    use Dispatchable;

    public function __construct(
        public readonly Campaign $campaign,
        public readonly Subscriber $subscriber,
        public readonly DeliveryServer $server,
        public readonly ?string $messageId = null,
    ) {}
}
