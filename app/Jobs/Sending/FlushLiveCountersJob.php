<?php

declare(strict_types=1);

namespace App\Jobs\Sending;

use App\Enums\CampaignStatus;
use App\Events\CampaignProgressUpdated;
use App\Models\Campaign;
use App\Services\Stats\CampaignStatsRollup;
use App\Support\Queue\Queues;
use App\Support\Stats\LiveCounter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Live-counter flush (spec §4.6 F-4.6-004, WP-13, maintenance queue): drains
 * the cache counters that the tracking endpoints and send jobs increment
 * (`campaigns:{uid}:{metric}` / `track:{metric}`) and persists by recomputing
 * the touched campaigns' campaign_stats_daily rows from the source tables.
 * Draining + recompute (instead of adding drained deltas) keeps the rollup
 * idempotent — cache counters can never double-count into the DB.
 */
class FlushLiveCountersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct()
    {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(LiveCounter $counter, CampaignStatsRollup $rollup): void
    {
        $drained = $counter->drain();

        if ($drained === []) {
            return;
        }

        $uids = [];

        foreach (array_keys($drained) as $name) {
            if (preg_match('/^campaigns:([^:]+):/', $name, $match) === 1) {
                $uids[$match[1]] = true;
            }
        }

        if ($uids === []) {
            return;
        }

        $campaigns = Campaign::withoutTenancy()
            ->withTrashed()
            ->whereIn('uid', array_keys($uids))
            ->get();

        $ids = $campaigns->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($ids !== []) {
            $rollup->rollup($ids);
        }

        // Live progress for the campaign screens (spec §0.1 F-0.1-007, WP-24):
        // broadcast only while a campaign is actually sending. With
        // BROADCAST_CONNECTION=null (the default) this is a no-op.
        foreach ($campaigns as $campaign) {
            if ($campaign->status === CampaignStatus::Sending) {
                event(CampaignProgressUpdated::for($campaign));
            }
        }
    }
}
