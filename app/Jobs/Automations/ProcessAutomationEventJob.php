<?php

declare(strict_types=1);

namespace App\Jobs\Automations;

use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use App\Models\Subscriber;
use App\Services\Automations\Enroller;
use App\Services\Automations\TriggerMatcher;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * Consumes one recorded automation event (WP-21 event-driven trigger seam):
 * (a) enrolls the subscriber into every active automation whose trigger the
 * event feeds (config-matched), and (b) wakes runs parked on the event by
 * wait-for-event / delay-until-event nodes (resume_branch = 'event').
 */
class ProcessAutomationEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly int $customerId,
        public readonly string $event,
        public readonly int $subscriberId,
        public readonly array $context = [],
    ) {
        $this->onQueue(Queues::AUTOMATION);
    }

    public function handle(TriggerMatcher $matcher, Enroller $enroller): void
    {
        $subscriber = Subscriber::withoutTenancy()->find($this->subscriberId);

        if ($subscriber === null || (int) $subscriber->customer_id !== $this->customerId) {
            return;
        }

        // (a) Trigger enrollment.
        foreach ($matcher->automationsFor($this->customerId, $this->event) as $automation) {
            if ($matcher->matches($automation, $this->event, $subscriber, $this->context)) {
                $enroller->enter($automation, $subscriber, $this->context, 'event:'.$this->event);
            }
        }

        // (b) Wake parked runs.
        $waiting = AutomationRun::withoutTenancy()
            ->where('subscriber_id', $subscriber->id)
            ->where('status', AutomationRunStatus::Active->value)
            ->where('waiting_for', $this->event)
            ->with('currentNode')
            ->get();

        $now = Carbon::now();

        foreach ($waiting as $run) {
            $node = $run->currentNode;

            if ($node !== null && ! $matcher->waitFilterAccepts($this->event, (array) ($node->config ?? []), $this->context)) {
                continue;
            }

            $run->forceFill([
                'waiting_for' => null,
                'resume_branch' => 'event',
                'wake_at' => $now,
            ])->save();

            AdvanceAutomationRunJob::dispatch((int) $run->id, (int) $run->current_node_id, $now->getTimestamp());
        }
    }
}
