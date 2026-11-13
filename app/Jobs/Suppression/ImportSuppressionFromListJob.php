<?php

declare(strict_types=1);

namespace App\Jobs\Suppression;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\EmailList;
use App\Models\SuppressionList;
use App\Services\Suppression\SuppressionListEmails;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued "import from list" (§2.9.11 F-2.9.11-002): copies every address
 * of one of the customer's EmailLists into the suppression list. The list
 * must belong to the same customer — enforced here again because the queue
 * never trusts the UI.
 */
class ImportSuppressionFromListJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $operationId,
        public readonly int $suppressionListId,
        public readonly int $emailListId,
    ) {
        $this->onQueue(Queues::IMPORTS);
    }

    public function handle(CurrentCustomer $current): void
    {
        $list = SuppressionList::withoutTenancy()->find($this->suppressionListId);
        $source = EmailList::withoutTenancy()->find($this->emailListId);

        if ($list === null || $source === null || $list->customer_id !== $source->customer_id) {
            return;
        }

        $current->runAs(null, function () use ($list, $source): void {
            app(SuppressionListEmails::class)->importFromEmailList($list, $source);
        });
    }

    public function idempotencyKey(): string
    {
        return 'suppression-from-list:'.$this->operationId;
    }
}
