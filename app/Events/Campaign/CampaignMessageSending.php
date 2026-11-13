<?php

declare(strict_types=1);

namespace App\Events\Campaign;

use App\Models\Campaign;
use App\Models\DeliveryServer;
use App\Models\Subscriber;
use App\Sending\Message;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HOOK `campaign.pre_send` (spec §6.1 F-6.1-012).
 *
 * Fired inside the REAL send path — App\Jobs\Sending\SendToSubscriberJob —
 * after the delivery server is picked and the spam gate has passed, and
 * immediately before the driver is handed the message.
 *
 * Listeners may:
 *   - MUTATE $message (it is deliberately not readonly, D-46): add headers,
 *     rewrite html/text, attach files;
 *   - CANCEL the send by calling cancel('reason'). A cancelled unit is
 *     finalised as `skipped` with that reason on the delivery log — exactly
 *     like the blacklist guard — never retried, never silently dropped.
 *
 * Runs synchronously on the queue worker inside the per-recipient job. A slow
 * listener slows every send: keep the work small or queue it yourself.
 */
class CampaignMessageSending
{
    use Dispatchable;

    private bool $cancelled = false;

    private ?string $cancelReason = null;

    public function __construct(
        public readonly Campaign $campaign,
        public readonly Subscriber $subscriber,
        public readonly DeliveryServer $server,
        public readonly Message $message,
    ) {}

    /** Abort this one send; $reason lands on the delivery log. */
    public function cancel(?string $reason = null): void
    {
        $this->cancelled = true;
        $this->cancelReason = $reason;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function cancelReason(): ?string
    {
        return $this->cancelReason;
    }
}
