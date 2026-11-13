<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRevision;
use Illuminate\Support\Carbon;

/**
 * Campaign content version history / autosave (spec §2.3.3 F-2.3.3-019).
 *
 * Mirrors TemplateRevisions (§2.5.4 F-2.5.4-008) deliberately — same table
 * shape, same pruning rule, same "a restore is itself undoable" guarantee —
 * so the two builders behave identically.
 *
 * {@see record()} is idempotent against no-op saves: an autosave whose content
 * is byte-identical to the newest revision writes nothing, so opening the
 * builder and closing it does not bury the real history under duplicates.
 */
class CampaignRevisions
{
    public function record(
        Campaign $campaign,
        string $origin = CampaignRevision::ORIGIN_MANUAL,
        bool $force = false,
    ): ?CampaignRevision {
        $latest = $this->latest($campaign);

        if (! $force
            && $latest !== null
            && (string) $latest->content_html === (string) $campaign->content_html
            && (string) $latest->content_json === (string) $campaign->content_json) {
            return null;
        }

        $revision = $campaign->revisions()->create([
            'content_html' => $campaign->content_html,
            'content_json' => $campaign->content_json,
            'origin' => $origin,
            'created_at' => Carbon::now(),
        ]);

        $this->prune($campaign);

        return $revision;
    }

    public function prune(Campaign $campaign): void
    {
        $keep = max(1, (int) config('emp-campaigns.revisions_keep', config('emp-templates.revisions_keep', 20)));

        $staleIds = $campaign->revisions()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($staleIds->isNotEmpty()) {
            CampaignRevision::query()->whereIn('id', $staleIds)->delete();
        }
    }

    /** Restore content from a revision; the pre-restore state is kept first. */
    public function restore(Campaign $campaign, CampaignRevision $revision): void
    {
        abort_unless((int) $revision->campaign_id === (int) $campaign->id, 404);

        // Forced: the restore boundary is marked in history even when the
        // pre-restore content happens to equal the newest revision, so the
        // "a restore is itself undoable" promise is visible, not implied.
        $this->record($campaign, CampaignRevision::ORIGIN_RESTORE, force: true);

        $campaign->forceFill([
            'content_html' => $revision->content_html,
            'content_json' => $revision->content_json,
        ])->save();
    }

    public function latest(Campaign $campaign): ?CampaignRevision
    {
        return $campaign->revisions()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return \Illuminate\Support\Collection<int, CampaignRevision> */
    public function history(Campaign $campaign): \Illuminate\Support\Collection
    {
        return $campaign->revisions()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
