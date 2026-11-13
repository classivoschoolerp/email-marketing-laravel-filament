<?php

declare(strict_types=1);

namespace App\Jobs\Lists;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Services\Lists\ListCopyService;
use App\Services\Lists\SubscriberCloner;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Split-list tool (spec F-2.2.13-001): divides a list into N part lists by
 * count (X subscribers per part) or percentage (X% per part), as a
 * background job. Part lists are structural copies (fields, options,
 * segments, company settings) and receive copies of the subscribers — the
 * source list is left intact (non-destructive, like the copy tool). The
 * final part takes the remainder.
 */
class SplitListJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public const MODE_COUNT = 'count';

    public const MODE_PERCENT = 'percent';

    public int $timeout = 3600;

    public function __construct(
        public readonly int $customerId,
        public readonly int $listId,
        public readonly string $mode,
        public readonly int $value,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(CurrentCustomer $current, ListCopyService $copier, SubscriberCloner $cloner): void
    {
        $customer = Customer::query()->find($this->customerId);

        if ($customer === null || $this->value < 1) {
            return;
        }

        $current->runAs($customer, function () use ($copier, $cloner): void {
            /** @var EmailList|null $list */
            $list = EmailList::query()->find($this->listId);

            if ($list === null) {
                return;
            }

            $total = $list->subscribers()->count();

            if ($total === 0) {
                return;
            }

            $chunkSize = $this->mode === self::MODE_PERCENT
                ? (int) ceil($total * min(100, $this->value) / 100)
                : $this->value;

            if ($chunkSize < 1) {
                return;
            }

            $parts = (int) ceil($total / $chunkSize);

            for ($part = 1; $part <= $parts; $part++) {
                $target = $copier->copy($list, withSubscribers: false, name: __('lists.tools.split_name', [
                    'name' => $list->name,
                    'part' => $part,
                    'parts' => $parts,
                ]));

                // The last part receives the remainder (F-2.2.13-001).
                $list->subscribers()
                    ->with(['fieldValues', 'list'])
                    ->orderBy('id')
                    ->skip(($part - 1) * $chunkSize)
                    ->take($chunkSize)
                    ->get()
                    ->each(fn ($subscriber) => $cloner->cloneTo($subscriber, $target));
            }
        });
    }

    public function idempotencyKey(): string
    {
        return 'list-split:'.$this->operationId;
    }
}
