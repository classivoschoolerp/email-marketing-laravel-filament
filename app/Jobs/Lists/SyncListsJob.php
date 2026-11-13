<?php

declare(strict_types=1);

namespace App\Jobs\Lists;

use App\Enums\SubscriberStatus;
use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Lists\SubscriberCloner;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sync-lists tool (spec F-2.2.13-002): compares two lists of the account by
 * email presence and, as a background job, either
 *  - copy:   subscribers of the source missing from the target are copied in,
 *  - move:   same, but the source rows are then marked "moved",
 *  - remove: subscribers of the source that ALSO exist in the target are
 *            removed from the source (presence-based dedupe).
 */
class SyncListsJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public const ACTION_COPY = 'copy';

    public const ACTION_MOVE = 'move';

    public const ACTION_REMOVE = 'remove';

    public const ACTIONS = [self::ACTION_COPY, self::ACTION_MOVE, self::ACTION_REMOVE];

    public int $timeout = 3600;

    public function __construct(
        public readonly int $customerId,
        public readonly int $sourceListId,
        public readonly int $targetListId,
        public readonly string $action,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(CurrentCustomer $current, SubscriberCloner $cloner): void
    {
        if (! in_array($this->action, self::ACTIONS, true) || $this->sourceListId === $this->targetListId) {
            return;
        }

        $customer = Customer::query()->find($this->customerId);

        if ($customer === null) {
            return;
        }

        $current->runAs($customer, function () use ($cloner): void {
            /** @var EmailList|null $source */
            $source = EmailList::query()->find($this->sourceListId);
            /** @var EmailList|null $target */
            $target = EmailList::query()->find($this->targetListId);

            if ($source === null || $target === null) {
                return;
            }

            $presence = fn (bool $present) => $source->subscribers()
                ->with(['fieldValues', 'list'])
                ->when(
                    $present,
                    fn ($query) => $query->whereExists($this->targetEmailQuery($target)),
                    fn ($query) => $query->whereNotExists($this->targetEmailQuery($target)),
                )
                ->orderBy('id');

            match ($this->action) {
                self::ACTION_COPY => $presence(false)->chunkById(500, function ($subscribers) use ($cloner, $target): void {
                    foreach ($subscribers as $subscriber) {
                        $cloner->cloneTo($subscriber, $target);
                    }
                }),
                self::ACTION_MOVE => $presence(false)->chunkById(500, function ($subscribers) use ($cloner, $target): void {
                    foreach ($subscribers as $subscriber) {
                        $cloner->cloneTo($subscriber, $target);
                        $subscriber->status = SubscriberStatus::Moved;
                        $subscriber->save();
                    }
                }),
                self::ACTION_REMOVE => $presence(true)->chunkById(500, function ($subscribers): void {
                    foreach ($subscribers as $subscriber) {
                        $subscriber->delete();
                    }
                }),
                default => null,
            };
        });
    }

    public function idempotencyKey(): string
    {
        return 'list-sync:'.$this->action.':'.$this->operationId;
    }

    /** Correlated EXISTS on the target list's emails (presence comparison). */
    private function targetEmailQuery(EmailList $target): \Closure
    {
        return function ($query) use ($target): void {
            $query->select('id')
                ->from('subscribers', 'target_subscribers')
                ->whereColumn('target_subscribers.email', 'subscribers.email')
                ->where('target_subscribers.list_id', $target->id)
                ->whereNull('target_subscribers.deleted_at');
        };
    }
}
