<?php

declare(strict_types=1);

namespace App\Services\Fbl;

use App\Enums\SubscriberStatus;
use App\Models\Campaign;
use App\Models\CampaignComplaintLog;
use App\Models\FblServer;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Services\Mailboxes\MailboxClientFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Polls one FBL server's mailbox and processes ARF complaint reports
 * (spec §2.9.5, WP-15): parse → attribute → default complaint action
 * (F-2.9.5-003): unsubscribe THROUGH THE MODEL + global-blacklist the
 * address with source='fbl' → append a campaign_complaint_logs row →
 * optionally delete the message → stamp last_run_at. Non-ARF messages are
 * skipped (still counted as processed, deleted per config).
 *
 * Runs from the fbl:process command or the queued ProcessFblServerJob —
 * never synchronously in a UI request.
 */
class FblProcessor
{
    public const SOURCE_FBL = 'fbl';

    public function __construct(
        private readonly MailboxClientFactory $clients,
        private readonly ArfParser $parser,
    ) {}

    /** @return int processed message count */
    public function process(FblServer $server): int
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
                    Log::warning('FBL message processing failed.', [
                        'fbl_server_id' => $server->id,
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

    private function handleMessage(FblServer $server, string $raw): void
    {
        $complaint = $this->parser->parse($raw);

        if (! $complaint->isArf) {
            Log::debug('Skipping non-ARF message in FBL mailbox.', ['fbl_server_id' => $server->id]);

            return;
        }

        [$campaignId, $subscriber] = $this->attribute($complaint);

        if ($subscriber !== null) {
            // Default complaint action (F-2.9.5-003): unsubscribe + blacklist.
            $subscriber->forceFill([
                'complaints_count' => (int) $subscriber->complaints_count + 1,
            ]);
            $subscriber->status = SubscriberStatus::Unsubscribed;
            $subscriber->save();
        }

        $email = $subscriber?->email ?? $complaint->recipient;

        if ($email !== null) {
            GlobalBlacklistEmail::query()->firstOrCreate(
                ['email' => $email],
                [
                    'reason' => __('infra.blacklist.complaint_reason'),
                    'source' => self::SOURCE_FBL,
                    'customer_id' => $subscriber?->customer_id,
                ],
            );
        }

        CampaignComplaintLog::query()->create([
            'customer_id' => $subscriber?->customer_id,
            'campaign_id' => $campaignId,
            'subscriber_id' => $subscriber?->id,
            'fbl_server_id' => $server->id,
            'feedback_type' => $complaint->feedbackType,
            'email' => $email,
            'message_id' => $complaint->originalMessageId,
            'message' => $complaint->excerpt,
        ]);
    }

    /**
     * @return array{0: int|null, 1: Subscriber|null}
     */
    private function attribute(ParsedComplaint $complaint): array
    {
        $subscriber = null;
        $campaignId = null;

        if ($complaint->subscriberRef !== null && $complaint->subscriberRef !== '') {
            $query = Subscriber::withoutTenancy();
            $subscriber = ctype_digit($complaint->subscriberRef)
                ? $query->find((int) $complaint->subscriberRef)
                : $query->where('uid', $complaint->subscriberRef)->first();
        }

        if ($complaint->campaignRef !== null && $complaint->campaignRef !== '') {
            $query = Campaign::withoutTenancy();
            $campaign = ctype_digit($complaint->campaignRef)
                ? $query->find((int) $complaint->campaignRef)
                : $query->where('uid', $complaint->campaignRef)->first();
            $campaignId = $campaign?->id;
        }

        if (($subscriber === null || $campaignId === null)
            && $complaint->originalMessageId !== null
            && Schema::hasTable('campaign_delivery_logs')
            && Schema::hasColumn('campaign_delivery_logs', 'message_id')) {
            $row = DB::table('campaign_delivery_logs')
                ->where('message_id', $complaint->originalMessageId)
                ->first();

            if ($row !== null) {
                $campaignId ??= isset($row->campaign_id) ? (int) $row->campaign_id : null;

                if ($subscriber === null && isset($row->subscriber_id)) {
                    $subscriber = Subscriber::withoutTenancy()->find((int) $row->subscriber_id);
                }
            }
        }

        if ($subscriber === null && $complaint->recipient !== null) {
            $subscriber = Subscriber::withoutTenancy()
                ->where('email', $complaint->recipient)
                ->orderByDesc('id')
                ->first();
        }

        return [$campaignId, $subscriber];
    }
}
