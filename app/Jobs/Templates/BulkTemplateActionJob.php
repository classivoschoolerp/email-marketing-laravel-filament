<?php

declare(strict_types=1);

namespace App\Jobs\Templates;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued template bulk actions (Part 8 F-Phase-3-Differentiat-006, docs/ARCHITECTURE.md
 * rule 5). Filament's select-all header checkbox selects every record matching
 * the current filters — not just the page — so "delete selected" and "move to
 * category" are unbounded and must not run inside the Livewire request.
 *
 * D-28 split, identical to BulkCampaignActionJob: the CALLER filters the
 * selection (a queued job has no actor), the JOB re-checks the one invariant
 * that is actor-independent — system/gallery rows (customer_id NULL) are
 * read-only for customers (§3.14) and are skipped here too.
 */
class BulkTemplateActionJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public const ACTION_DELETE = 'delete';

    public const ACTION_MOVE_CATEGORY = 'move_category';

    public const ACTIONS = [self::ACTION_DELETE, self::ACTION_MOVE_CATEGORY];

    /** @param list<int> $templateIds rows the caller already authorized (D-28) */
    public function __construct(
        public readonly string $action,
        public readonly array $templateIds,
        public readonly int $customerId,
        public readonly string $operationId,
        public readonly ?int $categoryId = null,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(CurrentCustomer $current): void
    {
        if (! in_array($this->action, self::ACTIONS, true) || $this->templateIds === []) {
            return;
        }

        $customer = Customer::query()->withoutGlobalScopes()->find($this->customerId);

        if ($customer === null) {
            return;
        }

        $current->runAs($customer, function (): void {
            foreach (array_chunk($this->templateIds, 200) as $chunk) {
                // The tenant scope is deliberately left ON: it is the fence
                // that keeps a bulk inside its own tenant, and it also hides
                // the read-only NULL-customer gallery rows.
                $templates = EmailTemplate::query()
                    ->whereKey($chunk)
                    ->where('customer_id', $this->customerId)
                    ->get();

                foreach ($templates as $template) {
                    $this->apply($template);
                }
            }
        });
    }

    private function apply(EmailTemplate $template): void
    {
        if ($template->isSystem()) {
            return; // §3.14: gallery rows are read-only for customers
        }

        match ($this->action) {
            self::ACTION_DELETE => $template->delete(),
            self::ACTION_MOVE_CATEGORY => $template->forceFill(['category_id' => $this->categoryId])->save(),
            default => null,
        };
    }

    public function idempotencyKey(): string
    {
        return 'templates-bulk:'.$this->action.':'.$this->operationId;
    }
}
