<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Validation\ValidationException;

/**
 * The single authority for campaign status changes (spec §2.3.1/§2.3.9).
 *
 * Transition map (honest — no transition exists that the product cannot
 * actually perform at this wave):
 *
 *   draft            → scheduled | pending-approval
 *   pending-approval → scheduled | draft | blocked | cancelled
 *   scheduled        → sending | paused | draft | cancelled | blocked
 *   sending          → paused | sent | cancelled | blocked      (WP-13 flips sending/sent)
 *   paused           → sending | scheduled | cancelled | blocked
 *   blocked          → draft | cancelled
 *   cancelled        → draft
 *   sent             → ∅ (terminal — copy the campaign instead)
 */
class CampaignStatusMachine
{
    /** @var array<string, list<CampaignStatus>> */
    private const TRANSITIONS = [
        'draft' => [CampaignStatus::Scheduled, CampaignStatus::PendingApproval],
        'pending-approval' => [CampaignStatus::Scheduled, CampaignStatus::Draft, CampaignStatus::Blocked, CampaignStatus::Cancelled],
        'scheduled' => [CampaignStatus::Sending, CampaignStatus::Paused, CampaignStatus::Draft, CampaignStatus::Cancelled, CampaignStatus::Blocked],
        'sending' => [CampaignStatus::Paused, CampaignStatus::Sent, CampaignStatus::Cancelled, CampaignStatus::Blocked],
        'paused' => [CampaignStatus::Sending, CampaignStatus::Scheduled, CampaignStatus::Cancelled, CampaignStatus::Blocked],
        'blocked' => [CampaignStatus::Draft, CampaignStatus::Cancelled],
        'cancelled' => [CampaignStatus::Draft],
        'sent' => [],
    ];

    public function canTransition(CampaignStatus $from, CampaignStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value], true);
    }

    /** @return list<CampaignStatus> */
    public function allowedFrom(CampaignStatus $from): array
    {
        return self::TRANSITIONS[$from->value];
    }

    /**
     * Apply a transition, persisting the new status.
     *
     * @throws ValidationException when the transition is illegal
     */
    public function transition(Campaign $campaign, CampaignStatus $to): void
    {
        if (! $this->canTransition($campaign->status, $to)) {
            throw ValidationException::withMessages([
                'status' => __('campaigns.errors.illegal_transition', [
                    'from' => $campaign->status->label(),
                    'to' => $to->label(),
                ]),
            ]);
        }

        $from = $campaign->status;

        $campaign->status = $to;
        $campaign->save();

        // WP-35 (§2.3.4 F-2.3.4-022): the per-campaign webhook for the two
        // lifecycle events this class owns. `started`/`finished` belong to the
        // send path (campaigns:send / FinishCampaignSendJob) and are fired
        // there, so each event has exactly one producer.
        if ($to === CampaignStatus::Paused) {
            app(CampaignWebhookNotifier::class)->fire($campaign, CampaignWebhookNotifier::EVENT_PAUSED);
        } elseif ($from === CampaignStatus::Paused
            && ($to === CampaignStatus::Sending || $to === CampaignStatus::Scheduled)) {
            app(CampaignWebhookNotifier::class)->fire($campaign, CampaignWebhookNotifier::EVENT_RESUMED);
        }
    }

    /** May the campaign be paused right now? */
    public function canPause(Campaign $campaign): bool
    {
        return $this->canTransition($campaign->status, CampaignStatus::Paused);
    }

    /** May the campaign be resumed right now? */
    public function canResume(Campaign $campaign): bool
    {
        return $campaign->status === CampaignStatus::Paused;
    }

    /**
     * Resume target: a paused campaign that already started sending goes
     * back to sending; otherwise back to scheduled.
     */
    public function resume(Campaign $campaign): void
    {
        $target = $campaign->send_started_at !== null
            ? CampaignStatus::Sending
            : CampaignStatus::Scheduled;

        $this->transition($campaign, $target);
    }
}
