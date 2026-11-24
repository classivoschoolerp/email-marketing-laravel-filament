<?php

declare(strict_types=1);

namespace App\Jobs\Landing;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\LandingPage;
use App\Services\Landing\LandingPageCopier;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Duplicate a landing page off the request (spec §2.6 F-2.6-014, docs/ARCHITECTURE.md
 * rule 5).
 *
 * Queued because the copy is unbounded work: it walks every uploaded asset
 * under the page's directory and copies it, and a media-heavy page can carry
 * tens of megabytes. Doing that inside a Livewire action would hold the
 * panel's PHP-FPM worker for the duration.
 *
 * Idempotent on the operation id, so a double-clicked button or a worker that
 * dies mid-copy and retries can never leave the customer with two copies.
 */
class DuplicateLandingPageJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $landingPageId,
        public readonly int $customerId,
        public readonly string $operationId,
        public readonly ?string $name = null,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function idempotencyKey(): string
    {
        return 'landing:duplicate:'.$this->operationId;
    }

    public function handle(CurrentCustomer $current, LandingPageCopier $copier): void
    {
        $customer = Customer::query()->withoutGlobalScopes()->find($this->customerId);
        $page = LandingPage::withoutTenancy()->find($this->landingPageId);

        if ($customer === null || $page === null || (int) $page->customer_id !== (int) $customer->id) {
            // Nothing to do — and NOT a failure worth retrying.
            return;
        }

        $current->runAs($customer, function () use ($copier, $page): void {
            $copier->duplicate($page, $this->name);
        });
    }
}
