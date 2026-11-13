<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCronJob;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Models\Campaign;
use App\Models\CronRun;
use App\Services\Campaigns\CampaignCopier;
use App\Services\Campaigns\CampaignStatusMachine;
use App\Services\Campaigns\RecurringSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * `campaigns:process-recurring` (spec §0.6 F-0.6-003, every minute).
 * MailWizz child-copy semantics (D-56 engine): each due occurrence spawns a
 * one-shot Regular child via CampaignCopier (content + options + attachments
 * copied, counters/secrets reset), which campaigns:send picks up like any
 * scheduled campaign — so every run keeps its own delivery log and report.
 * The parent stays `scheduled`, its rec_occurrences advances and next_run_at
 * is recomputed (RecurringSchedule honors rec_starts_at/rec_ends_at/
 * rec_max_occurrences); when the schedule is exhausted next_run_at becomes
 * null and the parent is never due again.
 */
class CampaignsProcessRecurringCommand extends Command
{
    use RunsAsCronJob;

    public const JOB_NAME = 'campaigns:process-recurring';

    protected $signature = 'campaigns:process-recurring';

    protected $description = 'Spawn one-shot child runs for due recurring campaigns.';

    protected function process(CronRun $run): int
    {
        $copier = app(CampaignCopier::class);
        $machine = app(CampaignStatusMachine::class);
        $recurring = app(RecurringSchedule::class);
        $spawned = 0;

        $due = Campaign::withoutTenancy()
            ->where('type', CampaignType::Recurring)
            ->where('status', CampaignStatus::Scheduled)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', Carbon::now())
            ->orderBy('next_run_at')
            ->get();

        foreach ($due as $campaign) {
            try {
                $child = $copier->copy(
                    $campaign,
                    withContent: true,
                    withOptions: true,
                    name: $campaign->name.' — '.Carbon::now()->format('Y-m-d H:i'),
                );

                $child->forceFill([
                    'type' => CampaignType::Regular,
                    'send_at' => Carbon::now(),
                    'send_immediately' => true,
                    'rec_interval' => null,
                    'rec_cron' => null,
                    'rec_days_of_week' => null,
                    'rec_day_of_month' => null,
                    'rec_starts_at' => null,
                    'rec_ends_at' => null,
                    'rec_max_occurrences' => null,
                    'next_run_at' => null,
                ])->saveQuietly();

                $machine->transition($child, CampaignStatus::Scheduled);
                $spawned++;
            } catch (ValidationException $exception) {
                // Quota/copy failure must not wedge the schedule in a
                // once-a-minute retry loop — advance and surface the error.
                $this->warn(__('sending.recurring.spawn_failed', [
                    'campaign' => $campaign->name,
                    'error' => implode(' ', $exception->errors()['name'] ?? [$exception->getMessage()]),
                ]));
            }

            $campaign->forceFill([
                'rec_occurrences' => (int) $campaign->rec_occurrences + 1,
            ])->saveQuietly();

            $campaign->forceFill([
                'next_run_at' => $recurring->nextRunAt($campaign->refresh()),
            ])->saveQuietly();
        }

        return $spawned;
    }
}
