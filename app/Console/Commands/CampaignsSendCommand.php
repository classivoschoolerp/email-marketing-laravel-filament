<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCronJob;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Jobs\Sending\StartCampaignSendJob;
use App\Models\Campaign;
use App\Models\CronRun;
use App\Sending\Pipeline\SendWindow;
use App\Services\Campaigns\AbTest\AbWinnerSelector;
use App\Services\Campaigns\CampaignStatusMachine;
use App\Services\Campaigns\CampaignWebhookNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * `campaigns:send` (spec §0.6 F-0.6-001, every minute): moves due scheduled
 * regular campaigns into `sending` (status machine) and dispatches the
 * orchestration job that batches the recipients (F-0.4-001/F-4.6-001).
 * The send window (days + start/end hours, campaign TZ) gates pickup — a due
 * campaign outside its window simply stays scheduled until the window opens.
 *
 * Also self-heals: `sending` campaigns without a live wave (resumed after
 * pause, or a died worker) get a new wave covering only the recipients that
 * have no delivery-log row yet.
 */
class CampaignsSendCommand extends Command
{
    use RunsAsCronJob;

    public const JOB_NAME = 'campaigns:send';

    private const STALE_PENDING_MINUTES = 30;

    protected $signature = 'campaigns:send';

    protected $description = 'Pick up due scheduled campaigns and dispatch their send batches.';

    protected function process(CronRun $run): int
    {
        $machine = app(CampaignStatusMachine::class);
        $window = app(SendWindow::class);
        $started = 0;

        $due = Campaign::withoutTenancy()
            ->where('type', CampaignType::Regular)
            ->where('status', CampaignStatus::Scheduled)
            ->whereNotNull('send_at')
            ->where('send_at', '<=', Carbon::now())
            ->orderBy('send_at')
            ->get();

        foreach ($due as $campaign) {
            if (! $window->isOpen($campaign)) {
                continue; // window closed — stays scheduled (F-2.3.4-005/006)
            }

            $machine->transition($campaign, CampaignStatus::Sending);

            $campaign->forceFill([
                'send_started_at' => $campaign->send_started_at ?? Carbon::now(),
                'send_batch_id' => 'pending',
            ])->saveQuietly();

            app(CampaignWebhookNotifier::class)->fire($campaign, CampaignWebhookNotifier::EVENT_STARTED);

            StartCampaignSendJob::dispatch($campaign->id);
            $started++;
        }

        // Resume / self-heal: sending campaigns without a live wave.
        $resumable = Campaign::withoutTenancy()
            ->where('type', CampaignType::Regular)
            ->where('status', CampaignStatus::Sending)
            ->where(function ($query): void {
                $query->whereNull('send_batch_id')
                    ->orWhere(function ($stale): void {
                        $stale->where('send_batch_id', 'pending')
                            ->where('updated_at', '<', Carbon::now()->subMinutes(self::STALE_PENDING_MINUTES));
                    });
            })
            ->get();

        foreach ($resumable as $campaign) {
            if (! $window->isOpen($campaign)) {
                continue;
            }

            $claimed = Campaign::withoutTenancy()
                ->whereKey($campaign->id)
                ->where(function ($query): void {
                    $query->whereNull('send_batch_id')->orWhere('send_batch_id', 'pending');
                })
                ->update(['send_batch_id' => 'pending']);

            if ($claimed === 1) {
                StartCampaignSendJob::dispatch($campaign->id);
                $started++;
            }
        }

        // WP-35 (§2.3.6 F-2.3.6-007/008/011): decide A/B winners for campaigns
        // parked after their test wave. Declaring a winner releases the send
        // handle, so the NEXT tick of this same command starts the remainder
        // wave through the ordinary resume path above — there is no second
        // send code path.
        $started += $this->decideAbWinners();

        return $started;
    }

    /** @return int number of winners declared this tick */
    private function decideAbWinners(): int
    {
        $selector = app(AbWinnerSelector::class);
        $notifier = app(CampaignWebhookNotifier::class);
        $decided = 0;

        foreach ($selector->awaiting() as $campaign) {
            $winner = $selector->evaluate($campaign);

            if ($winner === null) {
                continue;
            }

            $decided++;

            $notifier->fire($campaign->refresh(), CampaignWebhookNotifier::EVENT_AB_WINNER, [
                'variant_uid' => (string) $winner->uid,
                'variant_label' => (string) $winner->label,
                'decision' => (string) $campaign->ab_winner_decision,
                'p_value' => $campaign->ab_winner_p_value,
            ]);
        }

        return $decided;
    }
}
