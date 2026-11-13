<?php

declare(strict_types=1);

namespace App\Jobs\Sending;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignVariantRecipient;
use App\Services\Campaigns\AbTest\AbTestManager;
use App\Services\Campaigns\AbTest\AbTestSplitter;
use App\Sending\Pipeline\RecipientLocalTime;
use App\Sending\Pipeline\SendTimeOptimizer;
use App\Services\Campaigns\CampaignRecipients;
use App\Services\Settings\SettingsReader;
use App\Support\Queue\Queues;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Send-wave orchestration (WP-13, queue Queues::CAMPAIGNS_HIGH): snapshots
 * the remaining recipients of a `sending` campaign under row locks
 * (F-4.6-002), applies the max_emails cap, chunks them by batch_size with
 * batch_interval_seconds inter-batch delays (F-4.6-001) and dispatches one
 * Bus::batch of SendToSubscriberJob workers (F-0.4-001 progress tracking).
 * Duplicate waves are impossible: campaigns:send claims the send_batch_id
 * sentinel before dispatching this job.
 *
 * A resume wave (after pause → resume, or a worker death) naturally targets
 * only recipients without a delivery-log row — the log table is the durable
 * record of who already got the campaign.
 */
class StartCampaignSendJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly int $campaignId)
    {
        $this->onQueue(Queues::CAMPAIGNS_HIGH);
    }

    public function handle(CampaignRecipients $recipients): void
    {
        $campaign = Campaign::withoutTenancy()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        if ($campaign->status !== CampaignStatus::Sending) {
            $this->clearPendingSentinel($campaign);

            return;
        }

        $query = $recipients->query($campaign);

        if ($query === null) {
            FinishCampaignSendJob::dispatch($campaign->id);

            return;
        }

        $alreadyLogged = CampaignDeliveryLog::query()
            ->where('campaign_id', $campaign->id)
            ->count();

        // Remaining units only — the delivery log records who already got it.
        $query->whereNotExists(function ($sub) use ($campaign): void {
            $sub->select(DB::raw(1))
                ->from('campaign_delivery_logs')
                ->whereColumn('campaign_delivery_logs.subscriber_id', 'subscribers.id')
                ->where('campaign_delivery_logs.campaign_id', $campaign->id);
        });

        // max_emails cap (F-2.3.4-003), counting everything already handled.
        $maxEmails = (int) ($campaign->max_emails ?? 0);

        if ($maxEmails > 0) {
            $allowance = max(0, $maxEmails - $alreadyLogged);
            $query->limit($allowance);
        }

        // Row-level locking while snapshotting (F-4.6-002): parallel workers
        // never take the same recipients. No-op on SQLite, FOR UPDATE on MySQL.
        $rows = DB::transaction(
            fn () => $query->reorder()->orderBy('subscribers.id')->lockForUpdate()->get(['subscribers.id', 'subscribers.uid']),
        );

        // WP-35 (§2.3.6): A/B split. During the TEST phase the wave is narrowed
        // to the deterministic test group; during the WINNER phase everything
        // left over is mailed the winning variant (F-2.3.6-005/008).
        $assignments = $this->applyAbSplit($campaign, $recipients, $rows);
        $rows = $assignments['rows'];

        $campaign->forceFill([
            'delivery_total' => $alreadyLogged + $rows->count(),
        ])->saveQuietly();

        if ($rows->isEmpty()) {
            $this->clearPendingSentinel($campaign);
            FinishCampaignSendJob::dispatch($campaign->id);

            return;
        }

        $batchSize = (int) ($campaign->batch_size ?? 0);

        if ($batchSize < 1) {
            $batchSize = $this->defaultBatchSize();
        }

        $interval = max(0, (int) ($campaign->batch_interval_seconds ?? 0));

        $jobs = [];

        // F-2.3.4-010: per-recipient timezone shift (empty unless enabled).
        $localDelays = app(RecipientLocalTime::class)->delaysFor($campaign, $rows);

        // F-2.3.4-011 (WP-46): learned per-recipient send hour (empty unless
        // enabled). Both maps may be non-empty; SendTimeOptimizer's docblock
        // fixes the precedence — the optimizer wins for a recipient it has a
        // prediction for, the timezone shift still applies to everyone else.
        $optimizer = app(SendTimeOptimizer::class);
        $optimizedDelays = $optimizer->delaysFor($campaign, $rows);

        if ($optimizer->enabled($campaign)) {
            $this->refreshStaleProfiles($campaign, $optimizer);
        }

        foreach ($rows->chunk($batchSize)->values() as $chunkIndex => $chunk) {
            foreach ($chunk as $row) {
                $variantId = $assignments['variants'][(int) $row->id] ?? null;

                $job = new SendToSubscriberJob(
                    campaignId: $campaign->id,
                    subscriberId: (int) $row->id,
                    campaignUid: (string) $campaign->uid,
                    subscriberUid: (string) $row->uid,
                    variantId: $variantId,
                );

                // Batch pacing plus, for a send-time A/B variable, that
                // variant's own offset (F-2.3.6-003).
                $delay = $interval > 0 && $chunkIndex > 0 ? $chunkIndex * $interval : 0;
                $delay += ($assignments['delays'][$variantId] ?? 0) * 60;
                $delay += $optimizedDelays[(int) $row->id] ?? $localDelays[(int) $row->id] ?? 0;

                if ($delay > 0) {
                    $job->delay($delay);
                }

                $jobs[] = $job;
            }
        }

        $campaignId = $campaign->id;

        $batch = Bus::batch($jobs)
            ->name('campaign:'.$campaign->uid)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($campaignId): void {
                FinishCampaignSendJob::dispatch($campaignId);
            })
            ->dispatch();

        // In async setups this records the live batch for progress/ETA; under
        // the sync driver the whole wave (finisher included) already ran.
        Campaign::withoutTenancy()
            ->whereKey($campaign->id)
            ->where('send_batch_id', 'pending')
            ->update(['send_batch_id' => $batch->id]);
    }

    /**
     * WP-35 §2.3.6 — narrow the wave to the A/B test group (or hand the
     * remainder the winner) and return the per-recipient variant map.
     *
     * The split is derived from the FULL audience, never from "what is left",
     * so a resume wave cannot re-draw a different test group; the assignment
     * rows are written once and re-read on every later wave, which makes the
     * mapping durable as well as deterministic.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array{
     *     rows: \Illuminate\Support\Collection<int, object>,
     *     variants: array<int, int>,
     *     delays: array<int, int>
     * }
     */
    private function applyAbSplit(Campaign $campaign, CampaignRecipients $recipients, $rows): array
    {
        $variants = app(AbTestManager::class)->sendableVariants($campaign);

        if ($variants === []) {
            return ['rows' => $rows, 'variants' => [], 'delays' => []];
        }

        $delays = [];

        foreach ($variants as $variant) {
            $delays[(int) $variant->id] = max(0, (int) $variant->send_delay_minutes);
        }

        if ($campaign->ab_phase === null) {
            $campaign->forceFill(['ab_phase' => 'test'])->saveQuietly();
            $campaign->ab_phase = 'test';
        }

        if ($campaign->ab_phase !== 'test') {
            // Winner phase: everyone remaining gets the winning variant, and
            // its send-time offset no longer applies (the test is over).
            $winnerId = (int) $campaign->ab_winner_variant_id;
            $map = [];

            foreach ($rows as $row) {
                $map[(int) $row->id] = $winnerId;
            }

            return ['rows' => $rows, 'variants' => $map, 'delays' => [$winnerId => 0]];
        }

        $assignments = $this->variantAssignments($campaign, $recipients, $variants);

        return [
            'rows' => $rows->filter(fn (object $row): bool => isset($assignments[(int) $row->id]))->values(),
            'variants' => $assignments,
            'delays' => $delays,
        ];
    }

    /**
     * @param  list<\App\Models\CampaignVariant>  $variants
     * @return array<int, int> subscriber id => variant id
     */
    private function variantAssignments(Campaign $campaign, CampaignRecipients $recipients, array $variants): array
    {
        $existing = CampaignVariantRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->pluck('campaign_variant_id', 'subscriber_id');

        if ($existing->isNotEmpty()) {
            return $existing->map(intval(...))->all();
        }

        $audience = $recipients->query($campaign)
            ?->reorder()
            ->orderBy('subscribers.id')
            ->get(['subscribers.id', 'subscribers.uid']) ?? collect();

        $split = app(AbTestSplitter::class)->split($campaign, $audience, $variants);

        $uids = $audience->pluck('uid', 'id');
        $now = now();
        $insert = [];
        $counts = [];

        foreach ($split['assignments'] as $subscriberId => $variantId) {
            $insert[] = [
                'campaign_id' => $campaign->id,
                'campaign_variant_id' => $variantId,
                'subscriber_id' => $subscriberId,
                'subscriber_uid' => (string) ($uids[$subscriberId] ?? ''),
                'created_at' => $now,
            ];

            $counts[$variantId] = ($counts[$variantId] ?? 0) + 1;
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            CampaignVariantRecipient::query()->insertOrIgnore($chunk);
        }

        foreach ($variants as $variant) {
            $variant->forceFill(['recipients' => $counts[(int) $variant->id] ?? 0])->save();
        }

        return $split['assignments'];
    }

    /**
     * F-2.3.4-011: keep the profiles fresh WITHOUT computing anything in the
     * send path. A stale (or absent) profile set for this campaign's list
     * queues one maintenance job — the current wave still goes out on whatever
     * the profiles say today, which is the honest behaviour: the alternative
     * is either blocking a send on an analytics pass or inventing an hour.
     * The job is ShouldBeUnique, so an every-minute send wave cannot stack it.
     */
    private function refreshStaleProfiles(Campaign $campaign, SendTimeOptimizer $optimizer): void
    {
        if ($campaign->customer_id === null) {
            return;
        }

        if ($optimizer->coverage($campaign)['stale']) {
            RefreshSendTimeProfilesJob::dispatch(
                (int) $campaign->customer_id,
                $campaign->email_list_id !== null ? (int) $campaign->email_list_id : null,
            );
        }
    }

    private function clearPendingSentinel(Campaign $campaign): void
    {
        Campaign::withoutTenancy()
            ->whereKey($campaign->id)
            ->where('send_batch_id', 'pending')
            ->update(['send_batch_id' => null]);
    }

    private function defaultBatchSize(): int
    {
        try {
            return max(1, SettingsReader::cron()->emailsPerBatch());
        } catch (Throwable) {
            return 500;
        }
    }
}
