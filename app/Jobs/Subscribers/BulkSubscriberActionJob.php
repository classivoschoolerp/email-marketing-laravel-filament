<?php

declare(strict_types=1);

namespace App\Jobs\Subscribers;

use App\Enums\SubscriberStatus;
use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * Queued bulk subscriber actions (spec F-2.2.4-007; docs/ARCHITECTURE.md rule 5 —
 * nothing bulk runs synchronously). Status changes go through the models so
 * the list's subscriber-actions cascade applies; copy/move clone rows
 * withoutEvents (an admin copy is not a subscribe event) and map custom
 * field values across lists by merge tag.
 */
class BulkSubscriberActionJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public const ACTION_DELETE = 'delete';

    public const ACTION_UNSUBSCRIBE = 'unsubscribe';

    public const ACTION_CONFIRM = 'confirm';

    public const ACTION_DISABLE = 'disable';

    public const ACTION_BLACKLIST = 'blacklist';

    public const ACTION_UNBLACKLIST = 'unblacklist';

    public const ACTION_COPY_TO_LIST = 'copy_to_list';

    public const ACTION_MOVE_TO_LIST = 'move_to_list';

    public const ACTIONS = [
        self::ACTION_DELETE, self::ACTION_UNSUBSCRIBE, self::ACTION_CONFIRM,
        self::ACTION_DISABLE, self::ACTION_BLACKLIST, self::ACTION_UNBLACKLIST,
        self::ACTION_COPY_TO_LIST, self::ACTION_MOVE_TO_LIST,
    ];

    /** @param list<int> $subscriberIds */
    public function __construct(
        public readonly int $customerId,
        public readonly string $action,
        public readonly array $subscriberIds,
        public readonly ?int $targetListId,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(CurrentCustomer $current): void
    {
        if (! in_array($this->action, self::ACTIONS, true)) {
            return;
        }

        $customer = Customer::query()->find($this->customerId);

        if ($customer === null) {
            return;
        }

        $current->runAs($customer, function (): void {
            foreach (array_chunk($this->subscriberIds, 500) as $chunk) {
                $subscribers = Subscriber::query()->whereKey($chunk)->get();

                foreach ($subscribers as $subscriber) {
                    $this->apply($subscriber);
                }
            }
        });
    }

    private function apply(Subscriber $subscriber): void
    {
        match ($this->action) {
            self::ACTION_DELETE => $subscriber->delete(),
            self::ACTION_UNSUBSCRIBE => $this->transition($subscriber, SubscriberStatus::Unsubscribed),
            self::ACTION_CONFIRM => $this->transition($subscriber, SubscriberStatus::Confirmed),
            self::ACTION_DISABLE => $this->transition($subscriber, SubscriberStatus::Disabled),
            self::ACTION_BLACKLIST => $this->transition($subscriber, SubscriberStatus::Blacklisted),
            self::ACTION_UNBLACKLIST => $subscriber->status === SubscriberStatus::Blacklisted
                ? $this->transition($subscriber, SubscriberStatus::Confirmed)
                : null,
            self::ACTION_COPY_TO_LIST => $this->copyTo($subscriber),
            self::ACTION_MOVE_TO_LIST => $this->moveTo($subscriber),
            default => null,
        };
    }

    private function transition(Subscriber $subscriber, SubscriberStatus $status): void
    {
        if ($subscriber->status === $status) {
            return;
        }

        $subscriber->status = $status;
        $subscriber->save();
    }

    private function copyTo(Subscriber $subscriber): ?Subscriber
    {
        $target = $this->targetList($subscriber);

        if ($target === null) {
            return null;
        }

        $exists = Subscriber::query()
            ->where('list_id', $target->id)
            ->where('email', $subscriber->email)
            ->exists();

        if ($exists) {
            return null;
        }

        // Map custom field values across lists by merge tag.
        $sourceTags = $subscriber->list?->fields()->pluck('tag', 'id') ?? collect();
        $targetIds = $target->fields()->pluck('id', 'tag');

        return Subscriber::withoutEvents(function () use ($subscriber, $target, $sourceTags, $targetIds): Subscriber {
            /** @var Subscriber $clone */
            $clone = $subscriber->replicate(['uid']);
            $clone->uid = (string) Str::uuid();
            $clone->list_id = $target->id;
            $clone->save();

            foreach ($subscriber->fieldValues()->get() as $value) {
                $tag = $sourceTags->get($value->list_field_id);
                $targetFieldId = $tag === null ? null : $targetIds->get($tag);

                if ($targetFieldId === null) {
                    continue;
                }

                $newValue = $value->replicate();
                $newValue->subscriber_id = $clone->id;
                $newValue->list_field_id = (int) $targetFieldId;
                $newValue->save();
            }

            return $clone;
        });
    }

    private function moveTo(Subscriber $subscriber): void
    {
        $target = $this->targetList($subscriber);

        if ($target === null) {
            return;
        }

        $this->copyTo($subscriber);

        // The source row is marked moved (spec §2.2.4 status set).
        $this->transition($subscriber, SubscriberStatus::Moved);
    }

    private function targetList(Subscriber $subscriber): ?EmailList
    {
        if ($this->targetListId === null || $this->targetListId === (int) $subscriber->list_id) {
            return null;
        }

        /** @var EmailList|null $list */
        $list = EmailList::query()->find($this->targetListId);

        return $list;
    }

    public function idempotencyKey(): string
    {
        return 'subscribers-bulk:'.$this->action.':'.$this->operationId;
    }
}
