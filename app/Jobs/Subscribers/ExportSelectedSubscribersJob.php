<?php

declare(strict_types=1);

namespace App\Jobs\Subscribers;

use App\Jobs\Concerns\IdempotentJob;
use App\Jobs\Exports\RunExportJob;
use App\Models\ExportJob;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Exports\ExportColumns;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * "Export selected" bulk action (spec F-2.2.4-007 / §2.2.8 scope=selected):
 * materializes an ExportJob history row for the selection — standard columns
 * plus every custom field of the lists the selected subscribers belong to —
 * then hands off to the §2.2.8 export engine (RunExportJob) for the chunked
 * CSV write, signed link and notification email.
 */
class ExportSelectedSubscribersJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    /** @param list<int> $subscriberIds */
    public function __construct(
        public readonly int $customerId,
        public readonly array $subscriberIds,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::EXPORTS);
    }

    public function handle(CurrentCustomer $current): void
    {
        $customer = \App\Models\Customer::query()->find($this->customerId);

        if ($customer === null || $this->subscriberIds === []) {
            return;
        }

        $current->runAs($customer, function (): void {
            $listIds = Subscriber::query()
                ->whereKey($this->subscriberIds)
                ->distinct()
                ->pluck('list_id');

            $fieldColumns = ListField::query()
                ->whereIn('list_id', $listIds)
                ->orderBy('sort_order')
                ->pluck('tag')
                ->unique()
                ->map(fn (string $tag): string => ExportColumns::FIELD_PREFIX.$tag)
                ->values()
                ->all();

            $export = ExportJob::create([
                'customer_id' => $this->customerId,
                'list_id' => count($listIds) === 1 ? (int) $listIds->first() : null,
                'scope' => ExportJob::SCOPE_SELECTED,
                'columns' => array_merge(ExportColumns::STANDARD, $fieldColumns),
                'subscriber_ids' => $this->subscriberIds,
            ]);

            RunExportJob::dispatch((int) $export->id);
        });
    }

    public function idempotencyKey(): string
    {
        return 'subscribers-export:'.$this->operationId;
    }
}
