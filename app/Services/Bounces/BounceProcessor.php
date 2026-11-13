<?php

declare(strict_types=1);

namespace App\Services\Bounces;

use App\Enums\BounceAction;
use App\Enums\BounceType;
use App\Enums\SubscriberStatus;
use App\Events\Campaign\CampaignBounced;
use App\Models\BounceServer;
use App\Models\Campaign;
use App\Models\CampaignBounceLog;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Services\Mailboxes\MailboxClientFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Polls one bounce server's mailbox and processes every message
 * (spec §2.9.4, WP-15): parse (DsnParser) → classify (BounceClassifier,
 * bounce_rules) → attribute → act on the subscriber THROUGH THE MODEL (so
 * list cascades fire) → append a campaign_bounce_logs row → optionally
 * delete the mailbox message → stamp last_run_at.
 *
 * Attribution order: X-EMP-Subscriber/X-EMP-Campaign header hints →
 * campaign_delivery_logs by original Message-ID (Schema-guarded — that table
 * belongs to the parallel WP-13 package) → recipient email lookup.
 *
 * Runs from the bounces:process command or the queued
 * ProcessBounceServerJob — never synchronously in a UI request.
 */
class BounceProcessor
{
    public const SOURCE_BOUNCE = 'bounce';

    public function __construct(
        private readonly MailboxClientFactory $clients,
        private readonly DsnParser $parser,
        private readonly BounceClassifier $classifier,
    ) {}

    /** @return int processed message count */
    public function process(BounceServer $server): int
    {
        $client = $this->clients->make($server->protocol);
        $client->connect($server->connection());

        $processed = 0;

        try {
            $batch = $server->max_per_batch > 0
                ? $server->max_per_batch
                : (int) config('emp-infra.bounces.default_batch', 200);

            foreach ($client->listMessages($batch) as $id) {
                try {
                    $this->handleMessage($server, $client->fetch($id));
                    $processed++;

                    if ($server->delete_after_processing) {
                        $client->delete($id);
                    }
                } catch (Throwable $exception) {
                    // One broken message must not stall the mailbox.
                    Log::warning('Bounce message processing failed.', [
                        'bounce_server_id' => $server->id,
                        'message_ref' => $id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        } finally {
            $client->disconnect();
            $server->forceFill(['last_run_at' => now()])->saveQuietly();
        }

        return $processed;
    }

    private function handleMessage(BounceServer $server, string $raw): void
    {
        $bounce = $this->parser->parse($raw);
        $type = $this->classifier->classify($bounce);

        [$campaignId, $subscriber] = $this->attribute($bounce);

        if ($subscriber !== null && $type !== BounceType::Internal) {
            $this->applyActions($server, $subscriber, $type);
        }

        $log = CampaignBounceLog::query()->create([
            'customer_id' => $subscriber?->customer_id,
            'campaign_id' => $campaignId,
            'subscriber_id' => $subscriber?->id,
            'bounce_server_id' => $server->id,
            'bounce_type' => $type,
            'email' => $subscriber?->email ?? $bounce->recipient,
            'message_id' => $bounce->originalMessageId,
            'message' => $bounce->excerpt,
        ]);

        // WP-36 (§6.1 F-6.1-012) — HOOK `campaign.on_bounce`. Dispatched last,
        // once the subscriber actions have been applied and the log row
        // exists, so a listener sees the finished state. Note that the caller
        // already isolates each message in its own try/catch: a listener that
        // throws costs one bounce, never the whole mailbox poll.
        CampaignBounced::dispatch($log, $type, $subscriber, $campaignId);
    }

    /**
     * @return array{0: int|null, 1: Subscriber|null} [campaign id, subscriber]
     */
    private function attribute(ParsedBounce $bounce): array
    {
        $subscriber = $this->subscriberFromRef($bounce->subscriberRef);
        $campaignId = $this->campaignIdFromRef($bounce->campaignRef);

        if (($subscriber === null || $campaignId === null) && $bounce->originalMessageId !== null) {
            [$logCampaignId, $logSubscriberId] = $this->deliveryLogLookup($bounce->originalMessageId);

            $campaignId ??= $logCampaignId;

            if ($subscriber === null && $logSubscriberId !== null) {
                $subscriber = Subscriber::withoutTenancy()->find($logSubscriberId);
            }
        }

        if ($subscriber === null && $bounce->recipient !== null) {
            $subscriber = Subscriber::withoutTenancy()
                ->where('email', $bounce->recipient)
                ->orderByDesc('id')
                ->first();
        }

        return [$campaignId, $subscriber];
    }

    private function subscriberFromRef(?string $ref): ?Subscriber
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        $query = Subscriber::withoutTenancy();

        return ctype_digit($ref)
            ? $query->find((int) $ref)
            : $query->where('uid', $ref)->first();
    }

    private function campaignIdFromRef(?string $ref): ?int
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        $query = Campaign::withoutTenancy();

        $campaign = ctype_digit($ref)
            ? $query->find((int) $ref)
            : $query->where('uid', $ref)->first();

        return $campaign?->id;
    }

    /**
     * Message-ID → campaign_delivery_logs (WP-13's table; guarded so this
     * package works before/without it).
     *
     * @return array{0: int|null, 1: int|null} [campaign_id, subscriber_id]
     */
    private function deliveryLogLookup(string $messageId): array
    {
        if (! Schema::hasTable('campaign_delivery_logs')
            || ! Schema::hasColumn('campaign_delivery_logs', 'message_id')) {
            return [null, null];
        }

        $row = DB::table('campaign_delivery_logs')->where('message_id', $messageId)->first();

        if ($row === null) {
            return [null, null];
        }

        return [
            isset($row->campaign_id) ? (int) $row->campaign_id : null,
            isset($row->subscriber_id) ? (int) $row->subscriber_id : null,
        ];
    }

    /**
     * Hard: always record bounce fields + status bounced, then the server's
     * configured extra action (F-2.9.4-007). Soft: record bounce fields; at
     * the N-soft-bounces threshold apply the configured action
     * (F-2.9.4-008). All transitions go through the model so list cascades
     * (SubscriberActionService) fire on every path.
     */
    private function applyActions(BounceServer $server, Subscriber $subscriber, BounceType $type): void
    {
        $subscriber->forceFill([
            'bounces_count' => (int) $subscriber->bounces_count + 1,
            'last_bounce_at' => now(),
        ]);

        if ($type === BounceType::Hard) {
            $subscriber->status = SubscriberStatus::Bounced;
            $subscriber->save();

            $this->applyConfiguredAction($server->hard_bounce_action, $subscriber);

            return;
        }

        // Soft bounce.
        $subscriber->save();

        if ($server->soft_bounce_threshold > 0
            && (int) $subscriber->bounces_count >= $server->soft_bounce_threshold) {
            if ($server->soft_bounce_action === BounceAction::MarkBounced) {
                $subscriber->status = SubscriberStatus::Bounced;
                $subscriber->save();
            } else {
                $this->applyConfiguredAction($server->soft_bounce_action, $subscriber);
            }
        }
    }

    private function applyConfiguredAction(BounceAction $action, Subscriber $subscriber): void
    {
        switch ($action) {
            case BounceAction::Unsubscribe:
                $subscriber->status = SubscriberStatus::Unsubscribed;
                $subscriber->save();
                break;

            case BounceAction::Blacklist:
                GlobalBlacklistEmail::query()->firstOrCreate(
                    ['email' => $subscriber->email],
                    [
                        'reason' => __('infra.blacklist.bounce_reason'),
                        'source' => self::SOURCE_BOUNCE,
                        'customer_id' => $subscriber->customer_id,
                    ],
                );
                break;

            case BounceAction::Delete:
                $subscriber->delete();
                break;

            case BounceAction::MarkBounced:
                // Baseline already applied.
                break;
        }
    }
}
