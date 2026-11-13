<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §0.1 F-0.1-007 — real-time campaign progress (Reverb / Pusher), WP-24.
 *
 * The send pipeline (WP-13) is FROZEN for this work package, so this event is
 * defined and tested here, and the coordinator wires it with ONE line at the
 * point where the delivery counters are flushed:
 *
 *     event(CampaignProgressUpdated::for($campaign));
 *
 * (in App\Support\Stats\LiveCounter's flush, or at the end of each send batch
 * in the campaign send job — both places already hold the fresh counters.)
 *
 * Until that line exists the event is inert; nothing else depends on it, and
 * with BROADCAST_CONNECTION unset Laravel drops the payload, so wiring it is
 * always safe.
 *
 * Channel: `private-campaigns.{uid}` — per campaign, not per customer, so a
 * customer only ever authorises the campaigns they own (the customer panel's
 * broadcasting auth route decides that; see docs/tech-stack.md).
 */
class CampaignProgressUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $campaignUid,
        public readonly int $total,
        public readonly int $sent,
        public readonly int $failed,
        public readonly string $status,
    ) {}

    /**
     * Build the event from a campaign model without importing it into the
     * signature — keeps this class usable from the frozen send pipeline.
     *
     * @param  object{uid: string, delivery_total: int, delivery_sent: int, delivery_failed: int, status: mixed}  $campaign
     */
    public static function for(object $campaign): self
    {
        $status = $campaign->status;

        return new self(
            campaignUid: (string) $campaign->uid,
            total: (int) $campaign->delivery_total,
            sent: (int) $campaign->delivery_sent,
            failed: (int) $campaign->delivery_failed,
            status: is_object($status) && property_exists($status, 'value')
                ? (string) $status->value
                : (string) $status,
        );
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('campaigns.'.$this->campaignUid)];
    }

    public function broadcastAs(): string
    {
        return 'campaign.progress';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'campaign_uid' => $this->campaignUid,
            'total' => $this->total,
            'sent' => $this->sent,
            'failed' => $this->failed,
            'status' => $this->status,
            'percent' => $this->percent(),
        ];
    }

    public function percent(): int
    {
        if ($this->total <= 0) {
            return 0;
        }

        return (int) min(100, round(($this->sent + $this->failed) / $this->total * 100));
    }
}
