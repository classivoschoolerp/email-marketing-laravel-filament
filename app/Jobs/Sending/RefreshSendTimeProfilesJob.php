<?php

declare(strict_types=1);

namespace App\Jobs\Sending;

use App\Services\Sending\SendTimeProfiler;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recompute Send Time Optimization profiles for one tenant (spec §2.3.4
 * F-2.3.4-011, WP-46), on the maintenance queue.
 *
 * This is the ONLY thing that writes `subscriber_send_time_profiles`. The send
 * path reads profiles and never builds one — a per-recipient histogram over a
 * year of tracking rows inside a send wave would be exactly the kind of bulk
 * work docs/ARCHITECTURE.md §5 forbids running synchronously.
 *
 * ShouldBeUnique on (customer, list) so a scheduler tick, an operator running
 * `send-time:profile` and a campaign's own "recompute" button cannot stack
 * three identical passes over the same subscribers.
 */
class RefreshSendTimeProfilesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Uniqueness lock lifetime; a long list must never wedge the queue. */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $customerId,
        public readonly ?int $listId = null,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function uniqueId(): string
    {
        return 'send-time-profiles:'.$this->customerId.':'.($this->listId ?? 'all');
    }

    public function handle(SendTimeProfiler $profiler): void
    {
        $profiler->refreshCustomer($this->customerId, $this->listId);
    }
}
