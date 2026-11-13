<?php

declare(strict_types=1);

namespace App\Jobs\Campaigns;

use App\Models\Campaign;
use App\Services\Webhooks\WebhookSignature;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers one per-campaign webhook (spec §2.3.4 F-2.3.4-022) on the webhooks
 * queue, signed with the same WebhookSignature contract as §2.14.2.
 *
 * The campaign is re-read at delivery time so the payload carries the current
 * counters rather than whatever they were when the event was queued.
 */
class DeliverCampaignWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $campaignId,
        public readonly string $event,
        public readonly array $payload = [],
    ) {
        $this->onQueue(Queues::WEBHOOKS);
    }

    /** @return list<int> backoff seconds between attempts */
    public function backoff(): array
    {
        return [30, 300];
    }

    public function handle(WebhookSignature $signer): void
    {
        $campaign = Campaign::withoutTenancy()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        $url = trim((string) $campaign->webhook_url);

        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return;
        }

        $timestamp = Carbon::now()->getTimestamp();
        $deliveryUid = (string) Str::uuid();

        $body = (string) json_encode([
            'event' => $this->event,
            'created_at' => Carbon::now()->toIso8601String(),
            'data' => $this->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $secret = (string) ($campaign->webhook_secret ?? '');

        $headers = $secret !== ''
            ? $signer->headers($body, $secret, $this->event, $deliveryUid, $timestamp)
            : [
                'Content-Type' => 'application/json',
                'User-Agent' => 'EMP-Webhooks/1.0',
                WebhookSignature::EVENT_HEADER => $this->event,
                WebhookSignature::DELIVERY_HEADER => $deliveryUid,
            ];

        try {
            Http::timeout(10)->withHeaders($headers)->withBody($body, 'application/json')->post($url);
        } catch (Throwable) {
            // A campaign webhook is observability, never part of the send.
        }
    }
}
