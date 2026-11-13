<?php

declare(strict_types=1);

namespace App\Listeners\Ops;

use App\Services\Ops\WorkerRegistry;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

/**
 * §6.6 F-6.6-008: keeps the worker/node registry current from Laravel's own
 * queue events, so no extra daemon is needed on a sending node.
 *
 * Registered from routes/modules/ops.php (D-41/D-51 precedent). Every handler
 * swallows its own errors: telemetry must never fail a job.
 */
class WorkerRegistryListener
{
    public function __construct(private readonly WorkerRegistry $registry) {}

    public function handleJobProcessing(JobProcessing $event): void
    {
        $this->guard(function () use ($event): void {
            $this->registry->jobStarted(
                jobName: $event->job->resolveName(),
                jobId: $event->job->getJobId() === null ? null : (string) $event->job->getJobId(),
                queue: $event->job->getQueue(),
                connection: $event->connectionName,
            );
        });
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        $this->guard(fn () => $this->registry->jobFinished(failed: false));
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $this->guard(fn () => $this->registry->jobFinished(failed: true));
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Registry writes are diagnostics — never let them break a job.
        }
    }
}
