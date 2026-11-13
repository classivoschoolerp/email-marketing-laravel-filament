<?php

declare(strict_types=1);

namespace App\Jobs\Lists;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Services\Lists\ListCopyService;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued list copy (spec F-2.2.14-001) — copies run in the background so
 * large lists never block the UI (docs/ARCHITECTURE.md rule 5).
 */
class CopyListJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $customerId,
        public readonly int $listId,
        public readonly bool $withSubscribers,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(CurrentCustomer $current, ListCopyService $copier): void
    {
        $customer = Customer::query()->find($this->customerId);

        if ($customer === null) {
            return;
        }

        $current->runAs($customer, function () use ($copier): void {
            /** @var EmailList|null $list */
            $list = EmailList::query()->find($this->listId);

            if ($list !== null) {
                $copier->copy($list, $this->withSubscribers);
            }
        });
    }

    public function idempotencyKey(): string
    {
        return 'list-copy:'.$this->operationId;
    }
}
