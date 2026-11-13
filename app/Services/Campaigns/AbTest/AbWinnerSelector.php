<?php

declare(strict_types=1);

namespace App\Services\Campaigns\AbTest;

use App\Enums\AbDecisionMode;
use App\Jobs\Sending\FinishCampaignSendJob;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignVariant;
use App\Services\Campaigns\CampaignRecipients;
use Illuminate\Support\Carbon;

/**
 * Chooses the winning variant and hands the remainder back to the send path
 * (spec §2.3.6 F-2.3.6-006/007/008/011).
 *
 * Called every minute from `campaigns:send` for campaigns parked in the
 * post-test wait (`ab_phase = test`, `ab_test_finished_at` stamped, no winner
 * yet). Three decision modes, see {@see AbDecisionMode}:
 *
 *  - manual      → nothing happens here; {@see chooseManually()} is the UI hook;
 *  - window      → leader on the criterion once N hours have elapsed;
 *  - confidence  → leader as soon as the two-proportion z-test against the
 *                  RUNNER-UP clears `ab_confidence_level`; the same N hours
 *                  then act as a documented fallback ceiling so a test that
 *                  will never reach significance still ships (`fallback`).
 *
 * Declaring a winner does NOT send anything itself: it flips `ab_phase` to
 * `winner` and releases the `send_batch_id` handle, so the very next
 * `campaigns:send` tick starts an ordinary resume wave. The remainder is
 * simply "everyone with no delivery-log row", which is exactly how resume
 * already works — no second send code path exists (F-2.3.6-008).
 */
final class AbWinnerSelector
{
    /** Sentinel parked in `send_batch_id` while a test waits for its winner. */
    public const WAITING_HANDLE = 'ab-wait';

    public const DECISION_MANUAL = 'manual';

    public const DECISION_WINDOW = 'window';

    public const DECISION_CONFIDENCE = 'confidence';

    public const DECISION_FALLBACK = 'fallback';

    public function __construct(
        private readonly AbTestStats $stats,
        private readonly SignificanceTest $significance,
        private readonly CampaignRecipients $recipients,
    ) {}

    /** Campaigns parked waiting for a winner decision. */
    public function awaiting(): \Illuminate\Database\Eloquent\Collection
    {
        return Campaign::withoutTenancy()
            ->where('ab_enabled', true)
            ->where('ab_phase', 'test')
            ->whereNotNull('ab_test_finished_at')
            ->whereNull('ab_winner_variant_id')
            ->get();
    }

    /**
     * Decide (or not) for one campaign. Returns the winner when one was
     * declared by this call.
     */
    public function evaluate(Campaign $campaign): ?CampaignVariant
    {
        if (! $campaign->ab_enabled
            || $campaign->ab_phase !== 'test'
            || $campaign->ab_test_finished_at === null
            || $campaign->ab_winner_variant_id !== null) {
            return null;
        }

        $mode = AbDecisionMode::tryFrom((string) $campaign->ab_decision_mode) ?? AbDecisionMode::Window;

        if ($mode === AbDecisionMode::Manual) {
            return null;
        }

        $criteria = $this->stats->criteria($campaign);

        if (! $criteria->isAvailable() || ! $criteria->isAvailableFor($campaign)) {
            // No data source for the chosen criterion (see AbWinnerCriteria):
            // refuse to invent a winner. The manual action stays available.
            //
            // WP-45 narrowed this from "the product cannot measure it" to
            // "THIS CAMPAIGN cannot": conversions became measurable when §2.12
            // landed, but a campaign with goal tracking off and no recorded
            // conversion still has nothing to rank, and declaring a 0-vs-0
            // winner would be exactly the invention this guard exists to stop.
            return null;
        }

        $ranked = $this->ranked($campaign);

        if ($ranked === []) {
            return null;
        }

        $elapsedHours = $this->elapsedHours($campaign);
        $windowHours = (int) $campaign->ab_decision_hours;

        if ($mode === AbDecisionMode::Window) {
            if ($elapsedHours < $windowHours) {
                return null;
            }

            return $this->declare($campaign, $ranked[0]['variant'], self::DECISION_WINDOW, null);
        }

        // Confidence mode (F-2.3.6-011).
        $leader = $ranked[0];
        $runnerUp = $ranked[1] ?? null;

        if ($runnerUp !== null) {
            $result = $this->significance->compare(
                $leader['successes'],
                $leader['trials'],
                $runnerUp['successes'],
                $runnerUp['trials'],
            );

            if ($result->isSignificantAt((float) $campaign->ab_confidence_level)
                && $leader['successes'] * $runnerUp['trials'] > $runnerUp['successes'] * $leader['trials']) {
                return $this->declare($campaign, $leader['variant'], self::DECISION_CONFIDENCE, $result->pValue);
            }
        }

        // Fallback ceiling — documented in the class docblock.
        if ($windowHours > 0 && $elapsedHours >= $windowHours) {
            return $this->declare($campaign, $leader['variant'], self::DECISION_FALLBACK, null);
        }

        return null;
    }

    /** UI hook for F-2.3.6-007 "or manual". */
    public function chooseManually(Campaign $campaign, CampaignVariant $variant): CampaignVariant
    {
        return $this->declare($campaign, $variant, self::DECISION_MANUAL, null);
    }

    /**
     * The live significance read-out for the report UI: leader vs runner-up.
     */
    public function currentSignificance(Campaign $campaign): ?SignificanceResult
    {
        $ranked = $this->ranked($campaign);

        if (count($ranked) < 2) {
            return null;
        }

        return $this->significance->compare(
            $ranked[0]['successes'],
            $ranked[0]['trials'],
            $ranked[1]['successes'],
            $ranked[1]['trials'],
        );
    }

    /**
     * Variants ordered best-first on the campaign's criterion.
     *
     * @return list<array{variant: CampaignVariant, successes: int, trials: int, value: float}>
     */
    public function ranked(Campaign $campaign): array
    {
        $criteria = $this->stats->criteria($campaign);
        [$successKey, $trialKey] = $criteria->proportion();

        $rows = [];

        foreach ($campaign->variants as $variant) {
            $row = $this->stats->row($campaign, $variant, $criteria);

            $rows[] = [
                'variant' => $variant,
                'successes' => (int) ($row[$successKey] ?? 0),
                'trials' => (int) ($row[$trialKey] ?? 0),
                'value' => (float) $row['criterion_value'],
                'position' => (int) $variant->position,
            ];
        }

        // Best rate first; a tie is broken by raw successes, then by composer
        // position so the ordering is total and reproducible.
        usort($rows, static function (array $a, array $b): int {
            return $b['value'] <=> $a['value']
                ?: $b['successes'] <=> $a['successes']
                ?: $a['position'] <=> $b['position'];
        });

        return array_map(static fn (array $row): array => [
            'variant' => $row['variant'],
            'successes' => $row['successes'],
            'trials' => $row['trials'],
            'value' => $row['value'],
        ], $rows);
    }

    /**
     * Persist the decision and release the send path (F-2.3.6-008).
     */
    private function declare(Campaign $campaign, CampaignVariant $winner, string $decision, ?float $pValue): CampaignVariant
    {
        CampaignVariant::query()
            ->where('campaign_id', $campaign->id)
            ->update(['is_winner' => false]);

        $winner->forceFill(['is_winner' => true])->save();

        $remainder = $this->remainderCount($campaign);
        $willSend = $campaign->ab_auto_send_winner && $remainder > 0;

        $campaign->forceFill([
            'ab_winner_variant_id' => $winner->id,
            'ab_winner_decided_at' => Carbon::now(),
            'ab_winner_decision' => $decision,
            'ab_winner_p_value' => $pValue,
            'ab_phase' => $willSend ? 'winner' : 'done',
            'send_batch_id' => $willSend ? null : self::WAITING_HANDLE,
        ])->saveQuietly();

        if (! $willSend) {
            // Nothing left to mail — close the campaign through the normal
            // epilogue so status/finished_at/notification stay in one place.
            FinishCampaignSendJob::dispatch((int) $campaign->id);
        }

        return $winner->refresh();
    }

    /** Recipients that have not been mailed yet (the remainder). */
    public function remainderCount(Campaign $campaign): int
    {
        $query = $this->recipients->query($campaign);

        if ($query === null) {
            return 0;
        }

        $logged = CampaignDeliveryLog::query()
            ->where('campaign_id', $campaign->id)
            ->pluck('subscriber_id');

        return (int) $query->whereNotIn('subscribers.id', $logged)->count();
    }

    private function elapsedHours(Campaign $campaign): float
    {
        $finished = $campaign->ab_test_finished_at;

        if ($finished === null) {
            return 0.0;
        }

        return max(0.0, (float) abs($finished->diffInRealMinutes(Carbon::now())) / 60);
    }
}
