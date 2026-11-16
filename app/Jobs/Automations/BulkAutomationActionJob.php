<?php

declare(strict_types=1);

namespace App\Jobs\Automations;

use App\Enums\AutomationStatus;
use App\Models\Automation;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued automation bulk actions (Part 8 F-Phase-3-Differentiat-006, docs/ARCHITECTURE.md
 * rule 5 — "nothing bulk runs synchronously").
 *
 * Closes the last deviation found by the WP-27B bulk-action re-audit: the
 * automations index flipped statuses and deleted rows inline inside the
 * Livewire request.
 *
 * Same division of labour as BulkCampaignActionJob (D-28): the CALLER filters
 * the selection with the acting customer's policy — a queued job has no
 * authenticated actor, so authorization must never move in here — and the JOB
 * re-checks the state, because an automation may legitimately have been
 * paused, published or deleted between queueing and running.
 */
class BulkAutomationActionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const ACTION_PAUSE = 'pause';

    public const ACTION_ACTIVATE = 'activate';

    public const ACTION_DELETE = 'delete';

    /**
     * @param  list<int>  $automationIds  already policy-filtered by the caller
     */
    public function __construct(
        private readonly array $automationIds,
        private readonly string $action,
    ) {
        $this->onQueue(Queues::AUTOMATION);
    }

    public function handle(): void
    {
        if ($this->automationIds === []) {
            return;
        }

        Automation::withoutTenancy()
            ->whereIn('id', $this->automationIds)
            ->chunkById(100, function ($automations): void {
                foreach ($automations as $automation) {
                    $this->apply($automation);
                }
            });
    }

    private function apply(Automation $automation): void
    {
        match ($this->action) {
            self::ACTION_PAUSE => $this->pause($automation),
            self::ACTION_ACTIVATE => $this->activate($automation),
            self::ACTION_DELETE => $automation->delete(),
            default => null,
        };
    }

    private function pause(Automation $automation): void
    {
        // Re-check: it may already have been paused since queueing.
        if ($automation->status === AutomationStatus::Active) {
            $automation->forceFill(['status' => AutomationStatus::Paused])->save();
        }
    }

    private function activate(Automation $automation): void
    {
        // Re-check: an unpublished automation must never go live.
        if ($automation->status !== AutomationStatus::Active && $automation->isPublished()) {
            $automation->forceFill(['status' => AutomationStatus::Active])->save();
        }
    }
}
