<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCronJob;
use App\Enums\InfraServerStatus;
use App\Models\CronRun;
use App\Models\EmailBoxMonitor;
use App\Services\Monitors\EmailBoxMonitorProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * mailbox-monitors:process (spec §0.6 F-0.6-007 / §2.9.6, WP-33): polls every
 * ACTIVE email box monitor every ten minutes and applies its condition →
 * action rules.
 *
 * Body wrapped in CronRunRecorder and gated by the §3.25.11 per-job toggle
 * through RunsAsCronJob (D-40). One failing mailbox never blocks the rest.
 * Already scheduled in routes/console.php behind a command-exists guard.
 */
class MailboxMonitorsProcessCommand extends Command
{
    use RunsAsCronJob;

    public const JOB_NAME = 'mailbox-monitors:process';

    protected $signature = 'mailbox-monitors:process {--monitor= : Process only this monitor id}';

    protected $description = 'Poll active email box monitors and apply their condition rules';

    protected function process(CronRun $run): int
    {
        $only = $this->option('monitor');
        $processor = app(EmailBoxMonitorProcessor::class);

        $monitors = EmailBoxMonitor::withoutTenancy()
            ->where('status', InfraServerStatus::Active->value)
            ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($monitors as $monitor) {
            try {
                $total += $processor->process($monitor);
            } catch (Throwable $exception) {
                Log::error('Email box monitor processing failed.', [
                    'email_box_monitor_id' => $monitor->id,
                    'error' => $exception->getMessage(),
                ]);
                $this->error(__('infra.cron.server_failed', [
                    'name' => $monitor->name,
                    'error' => $exception->getMessage(),
                ]));
            }
        }

        return $total;
    }
}
