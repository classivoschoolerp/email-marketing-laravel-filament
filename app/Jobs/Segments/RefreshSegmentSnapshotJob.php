<?php

declare(strict_types=1);

namespace App\Jobs\Segments;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\ListSegment;
use App\Services\Segments\SegmentSnapshotService;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * Freezes (or refreshes, or clears) a static segment's snapshot — spec
 * §2.2.10 F-2.2.10-013.
 *
 * Always queued: materialising a segment can touch every subscriber of a list,
 * which is exactly the kind of bulk work docs/ARCHITECTURE.md rule 5 forbids running
 * inside a request.
 *
 * A segment that is no longer static has its snapshot cleared instead, so
 * toggling static off and on again re-freezes from scratch rather than
 * resurrecting a stale set.
 *
 * The job resolves the segment withoutTenancy (workers have no request tenant)
 * and then runs the whole refresh AS the owning customer, so every query the
 * builder makes stays inside that tenant.
 */
class RefreshSegmentSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $segmentId,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    /** Convenience for UI call sites: one refresh per click, never de-duplicated. */
    public static function for(ListSegment $segment): self
    {
        return new self((int) $segment->id, (string) Str::uuid());
    }

    public function handle(CurrentCustomer $current, SegmentSnapshotService $snapshots): void
    {
        /** @var ListSegment|null $segment */
        $segment = ListSegment::withoutTenancy()->find($this->segmentId);

        if ($segment === null) {
            return;
        }

        $customer = Customer::query()->find($segment->customer_id);

        if ($customer === null) {
            return;
        }

        $current->runAs($customer, function () use ($segment, $snapshots): void {
            $segment->isStatic()
                ? $snapshots->refresh($segment)
                : $snapshots->clear($segment);
        });
    }

    public function idempotencyKey(): string
    {
        return 'segment-snapshot:'.$this->segmentId.':'.$this->operationId;
    }
}
