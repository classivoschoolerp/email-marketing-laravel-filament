<?php

declare(strict_types=1);

namespace App\Jobs\Lists;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListFieldOption;
use App\Models\ListSegment;
use App\Models\ListSegmentCondition;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Background purge after a typed-confirmation list delete (spec
 * F-2.2.14-002). The list row is soft-deleted synchronously (vanishes from
 * the UI at once); this job hard-deletes the data in chunks. Deletes run
 * withoutTenancy + explicit ids because queue workers have no request tenant.
 */
class PurgeListJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    private const CHUNK = 1000;

    public function __construct(
        public readonly int $customerId,
        public readonly int $listId,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(): void
    {
        /** @var EmailList|null $list */
        $list = EmailList::withoutTenancy()
            ->withTrashed()
            ->where('customer_id', $this->customerId)
            ->find($this->listId);

        if ($list === null) {
            return;
        }

        // Subscribers + their field values, chunked (spec §4.6: nothing bulk is synchronous).
        while (true) {
            $ids = Subscriber::withoutTenancy()
                ->withTrashed()
                ->where('list_id', $list->id)
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            SubscriberFieldValue::withoutTenancy()->whereIn('subscriber_id', $ids)->delete();
            Subscriber::withoutTenancy()->withTrashed()->whereKey($ids)->forceDelete();
        }

        $fieldIds = ListField::withoutTenancy()->where('list_id', $list->id)->pluck('id');
        ListFieldOption::withoutTenancy()->whereIn('list_field_id', $fieldIds)->delete();

        $segmentIds = ListSegment::withoutTenancy()->where('list_id', $list->id)->pluck('id');
        ListSegmentCondition::withoutTenancy()->whereIn('list_segment_id', $segmentIds)->delete();

        ListSegment::withoutTenancy()->where('list_id', $list->id)->delete();
        ListField::withoutTenancy()->where('list_id', $list->id)->delete();

        $list->forceDelete();
    }

    public function idempotencyKey(): string
    {
        return 'list-purge:'.$this->listId.':'.$this->operationId;
    }
}
