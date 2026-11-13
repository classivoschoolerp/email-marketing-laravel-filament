<?php

declare(strict_types=1);

namespace App\Jobs\Infra;

use App\Models\EmailBoxMonitor;
use App\Services\Monitors\EmailBoxMonitorProcessor;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued single-monitor run (spec §2.9.6, WP-33) — backs the admin "process
 * now" action so mailbox polling never happens inside a UI request
 * (docs/ARCHITECTURE.md rule 5). The scheduled path is mailbox-monitors:process.
 */
class ProcessEmailBoxMonitorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $emailBoxMonitorId)
    {
        $this->onQueue(Queues::BOUNCES);
    }

    public function handle(EmailBoxMonitorProcessor $processor): void
    {
        $monitor = EmailBoxMonitor::withoutTenancy()->find($this->emailBoxMonitorId);

        if ($monitor !== null) {
            $processor->process($monitor);
        }
    }
}
