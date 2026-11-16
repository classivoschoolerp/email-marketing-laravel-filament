<?php

declare(strict_types=1);

namespace App\Jobs\Automations;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\Automation;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Automations\Enroller;
use App\Services\Segments\SegmentQueryBuilder;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * F-2.4.2-019 — manual / bulk enrollment, queued (docs/ARCHITECTURE.md rule 5: nothing
 * bulk runs synchronously). Enrolls a whole list, a segment, or an explicit
 * subscriber id set into an automation; per-subscriber re-entry rules apply
 * inside Enroller. Idempotent per operation id.
 */
class EnrollSubscribersJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    /** @param list<int> $subscriberIds explicit ids (used when list/segment are null) */
    public function __construct(
        public readonly int $automationId,
        public readonly ?int $listId,
        public readonly ?int $segmentId,
        public readonly array $subscriberIds,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::AUTOMATION);
    }

    public function idempotencyKey(): string
    {
        return 'automation-enroll:'.$this->automationId.':'.$this->operationId;
    }

    public function handle(Enroller $enroller, SegmentQueryBuilder $segments): void
    {
        $automation = Automation::withoutTenancy()->find($this->automationId);

        if ($automation === null || ! $automation->isActive()) {
            return;
        }

        $query = null;

        if ($this->segmentId !== null) {
            $segment = ListSegment::withoutTenancy()->find($this->segmentId);

            if ($segment === null) {
                return;
            }

            $query = $segments->subscribers($segment)->withoutGlobalScopes();
        } elseif ($this->listId !== null) {
            $query = Subscriber::withoutTenancy()->where('list_id', $this->listId);
        } else {
            $query = Subscriber::withoutTenancy()->whereIn('id', $this->subscriberIds);
        }

        $query->where('customer_id', $automation->customer_id)
            ->chunkById(200, function ($subscribers) use ($enroller, $automation): void {
                foreach ($subscribers as $subscriber) {
                    $enroller->enter($automation, $subscriber, [], 'manual');
                }
            });
    }
}
