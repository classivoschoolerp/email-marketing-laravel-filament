<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCronJob;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Jobs\Automations\AdvanceAutomationRunJob;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\CronRun;
use App\Services\Automations\PollingTriggers;
use App\Services\Settings\SettingsReader;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * `automations:tick` (spec §0.6 F-0.6-004, every minute — WP-21):
 * (a) evaluates the polled triggers (date-based, campaign-not-opened,
 *     segment enters/exits — event-driven triggers ride model hooks +
 *     queued jobs instead) for every ACTIVE automation, and
 * (b) dispatches AdvanceAutomationRunJob (Queues::AUTOMATION) for every
 *     active run whose wake_at is due — idempotent per (run, node, wake),
 *     pre-checked against job_idempotency_keys so overlapping ticks never
 *     double-dispatch (WP-13 autoresponder pattern). Paused automations are
 *     excluded from both passes.
 *
 * Returns the processed count (enrollments + advancements dispatched) for
 * the cron_runs row (D-40).
 */
class AutomationsTickCommand extends Command
{
    use RunsAsCronJob;

    public const JOB_NAME = 'automations:tick';

    protected $signature = 'automations:tick';

    protected $description = 'Advance automation journey nodes (spec §0.6).';

    protected function process(CronRun $run): int
    {
        if (! Schema::hasTable('automations')) {
            $this->line(__('sending.cron.automations_pending'));

            return 0;
        }

        $processed = 0;
        $polling = app(PollingTriggers::class);

        $automations = Automation::withoutTenancy()
            ->where('status', AutomationStatus::Active->value)
            ->get();

        foreach ($automations as $automation) {
            $processed += $polling->evaluate($automation);
        }

        if ($automations->isEmpty()) {
            return $processed;
        }

        $due = AutomationRun::withoutTenancy()
            ->where('status', AutomationRunStatus::Active->value)
            ->whereNotNull('wake_at')
            ->where('wake_at', '<=', Carbon::now())
            ->whereIn('automation_id', $automations->pluck('id'))
            ->orderBy('wake_at')
            ->limit($this->batchLimit())
            ->get();

        foreach ($due as $dueRun) {
            if ($dueRun->current_node_id === null) {
                continue;
            }

            $key = 'automation-advance:'.$dueRun->id.':'.$dueRun->current_node_id
                .':'.Carbon::parse($dueRun->wake_at)->getTimestamp();

            // Async pickup between ticks: skip if the same wave is in flight.
            if (DB::table('job_idempotency_keys')->where('key', $key)->exists()) {
                continue;
            }

            AdvanceAutomationRunJob::dispatch(
                (int) $dueRun->id,
                (int) $dueRun->current_node_id,
                Carbon::parse($dueRun->wake_at)->getTimestamp(),
            );

            $processed++;
        }

        return $processed;
    }

    private function batchLimit(): int
    {
        try {
            return max(1, SettingsReader::cron()->subscribersPerBatch());
        } catch (Throwable) {
            return 1000;
        }
    }
}
