<?php

declare(strict_types=1);

namespace App\Jobs\Automations;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\AutomationRun;
use App\Services\Automations\RunAdvancer;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Advances one due run (spec §0.6 automations:tick — all advancement rides
 * Queues::AUTOMATION). Idempotent per (run, node, wake moment): the tick
 * firing twice dispatches the same key so the second copy is dropped by the
 * middleware, and a stale job whose run already moved on no-ops on the
 * current_node check. A paused automation releases the key so the run
 * resumes when the tick re-dispatches after reactivation.
 */
class AdvanceAutomationRunJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $runId,
        public readonly int $expectedNodeId,
        public readonly int $wakeTimestamp,
    ) {
        $this->onQueue(Queues::AUTOMATION);
    }

    public function idempotencyKey(): string
    {
        return 'automation-advance:'.$this->runId.':'.$this->expectedNodeId.':'.$this->wakeTimestamp;
    }

    public function handle(RunAdvancer $advancer): void
    {
        $run = AutomationRun::withoutTenancy()->find($this->runId);

        if ($run === null || ! $run->isActive()) {
            return;
        }

        if ((int) $run->current_node_id !== $this->expectedNodeId) {
            return; // already advanced by another wave
        }

        $automation = $run->automation()->first();

        if ($automation === null) {
            return;
        }

        if (! $automation->isActive()) {
            $this->releaseIdempotencyKey(); // paused — let the resume wave retry

            return;
        }

        $advancer->advance($run);
    }
}
