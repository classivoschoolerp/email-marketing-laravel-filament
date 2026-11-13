<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignFilter;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * §2.3.9 lifecycle actions that are not status transitions:
 * archive/unarchive (F-2.3.9-006), star/unstar (§2.3.1 F-2.3.1-008) and
 * resend-to-non-openers (F-2.3.9-004).
 *
 * Archiving is deliberately NOT a status: a sent campaign's status is
 * terminal (D-57) and its reports must keep working, so archiving is a
 * separate `archived_at` flag that only hides the row from the default index.
 */
class CampaignLifecycle
{
    public function __construct(
        private readonly CampaignCopier $copier,
        private readonly CampaignRecipients $recipients,
    ) {}

    /* ---------------------------------------------------------------- *
     *  F-2.3.9-006 archive
     * ---------------------------------------------------------------- */

    public function canArchive(Campaign $campaign): bool
    {
        return $campaign->archived_at === null
            && $campaign->status !== CampaignStatus::Sending;
    }

    public function archive(Campaign $campaign): void
    {
        if (! $this->canArchive($campaign)) {
            throw ValidationException::withMessages([
                'archived_at' => __('campaigns.archive.cannot_archive_sending'),
            ]);
        }

        $campaign->forceFill(['archived_at' => Carbon::now()])->save();
    }

    public function unarchive(Campaign $campaign): void
    {
        $campaign->forceFill(['archived_at' => null])->save();
    }

    /* ---------------------------------------------------------------- *
     *  F-2.3.1-008 star
     * ---------------------------------------------------------------- */

    public function toggleStar(Campaign $campaign): bool
    {
        $starred = ! $campaign->is_starred;

        $campaign->forceFill(['is_starred' => $starred])->save();

        return $starred;
    }

    /* ---------------------------------------------------------------- *
     *  F-2.3.9-004 resend to non-openers
     * ---------------------------------------------------------------- */

    /**
     * How many people were delivered the campaign but never opened it.
     * This is exactly what the follow-up campaign's `not_opened` filter will
     * select, so the preview and the send agree by construction.
     */
    public function nonOpenerCount(Campaign $campaign): int
    {
        $follow = $this->buildFollowUp($campaign, (string) $campaign->subject, persist: false);

        return $this->recipients->count($follow);
    }

    /**
     * Create the follow-up campaign: a full copy WITHOUT stats, a new subject
     * line, and a §2.3.7 `not_opened` filter pointed back at the original —
     * so the audience is computed by the same choke point every other count
     * uses, and stays correct if someone opens the original tomorrow.
     */
    public function resendToNonOpeners(Campaign $campaign, string $newSubject): Campaign
    {
        if ($campaign->status !== CampaignStatus::Sent) {
            throw ValidationException::withMessages([
                'subject' => __('campaigns.resend.not_sent'),
            ]);
        }

        return $this->buildFollowUp($campaign, $newSubject, persist: true);
    }

    /**
     * Shared builder. With `$persist` false the campaign is a TRANSIENT model
     * used only to count — nothing is written.
     */
    private function buildFollowUp(Campaign $campaign, string $subject, bool $persist): Campaign
    {
        $subject = trim($subject) !== '' ? trim($subject) : (string) $campaign->subject;

        if (! $persist) {
            $preview = $campaign->replicate();
            $preview->id = $campaign->id;
            $preview->exists = false;

            $filter = new CampaignFilter([
                'type' => CampaignFilter::TYPE_NOT_OPENED,
                'mode' => CampaignFilter::MODE_INCLUDE,
                'target_campaign_id' => $campaign->id,
                'position' => 0,
            ]);

            $preview->setRelation('filters', collect([$filter]));
            $preview->setRelation('targetLists', $campaign->targetLists);
            $preview->filter_match = CampaignFilter::MATCH_ALL;

            return $preview;
        }

        // CampaignCopier already drops every counter and run-state column, so
        // the follow-up starts with no stats of its own.
        $copy = $this->copier->copy(
            $campaign,
            name: mb_substr($campaign->name.' '.__('campaigns.resend.name_suffix'), 0, 191),
        );

        $copy->forceFill([
            'subject' => $subject,
            'filter_match' => CampaignFilter::MATCH_ALL,
            'resent_from_campaign_id' => $campaign->id,
            // A follow-up must never inherit the original's A/B state.
            'ab_enabled' => false,
            'ab_phase' => null,
            'ab_test_finished_at' => null,
            'ab_winner_variant_id' => null,
            'ab_winner_decided_at' => null,
            'ab_winner_decision' => null,
            'ab_winner_p_value' => null,
        ])->save();

        $copy->filters()->create([
            'type' => CampaignFilter::TYPE_NOT_OPENED,
            'mode' => CampaignFilter::MODE_INCLUDE,
            'target_campaign_id' => $campaign->id,
            'position' => 0,
        ]);

        return $copy->refresh();
    }
}
