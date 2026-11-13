<?php

declare(strict_types=1);

namespace App\Jobs\Lists;

use App\Enums\SubscriberStatus;
use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Services\Blacklist\BlacklistMatcher;
use App\Services\Lists\SubscriberCloner;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * Merge-lists tool (spec F-2.2.13-003): folds N source lists of the same
 * account into one target list as a background job, modelled on the WP-08
 * split/sync tools (maintenance queue, idempotent, silent).
 *
 * Rules, in the order they are applied per source subscriber:
 *
 *  1. Never resurrect: rows whose status is unsubscribed, blacklisted or
 *     moved are skipped outright, and any address the §3.17 BlacklistMatcher
 *     matches is skipped too (D-74). A merge must not re-add someone who
 *     opted out or was suppressed.
 *  2. Custom fields are mapped onto the target by merge tag — the shared
 *     {@see SubscriberCloner} contract. Tags with no counterpart on the
 *     target are skipped, never invented.
 *  3. Duplicates (the email already exists in the target) follow the chosen
 *     handling: `skip` leaves the target row untouched, `update` copies the
 *     source's mapped field values onto it. Neither ever changes the target
 *     row's status — see rule 1.
 *
 * Sources are left intact unless `deleteSources` is set, in which case each
 * merged source takes the same soft-delete + queued purge path as the
 * §2.2.14 delete action.
 */
class MergeListsJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    /** Duplicate handling (mirrors the list's import_duplicate_handling wording). */
    public const DUPLICATES_SKIP = 'skip';

    public const DUPLICATES_UPDATE = 'update';

    /** @return list<string> */
    public const DUPLICATE_MODES = [self::DUPLICATES_SKIP, self::DUPLICATES_UPDATE];

    /** Statuses that a merge must never carry into the target list. */
    public const NEVER_MERGED = [
        SubscriberStatus::Unsubscribed,
        SubscriberStatus::Blacklisted,
        SubscriberStatus::Moved,
    ];

    public int $timeout = 3600;

    /** @param list<int> $sourceListIds */
    public function __construct(
        public readonly int $customerId,
        public readonly array $sourceListIds,
        public readonly int $targetListId,
        public readonly string $duplicateHandling,
        public readonly bool $deleteSources,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(CurrentCustomer $current, SubscriberCloner $cloner, BlacklistMatcher $blacklist): void
    {
        if (! in_array($this->duplicateHandling, self::DUPLICATE_MODES, true)) {
            return;
        }

        $customer = Customer::query()->find($this->customerId);

        if ($customer === null) {
            return;
        }

        $current->runAs($customer, function () use ($cloner, $blacklist, $customer): void {
            /** @var EmailList|null $target */
            $target = EmailList::query()->find($this->targetListId);

            if ($target === null) {
                return;
            }

            // The global scope (D-05) is the tenancy authority: a foreign list
            // id simply does not resolve here, so it can never be merged.
            $sources = EmailList::query()
                ->whereIn('id', array_values(array_unique(array_map(intval(...), $this->sourceListIds))))
                ->whereKeyNot($target->id)
                ->orderBy('id')
                ->get();

            foreach ($sources as $source) {
                $this->mergeOne($source, $target, $cloner, $blacklist, (int) $customer->id);

                if ($this->deleteSources) {
                    $source->status = EmailList::STATUS_PENDING_DELETE;
                    $source->save();
                    $source->delete();

                    PurgeListJob::dispatch(
                        (int) $source->customer_id,
                        (int) $source->id,
                        (string) Str::uuid(),
                    );
                }
            }
        });
    }

    private function mergeOne(
        EmailList $source,
        EmailList $target,
        SubscriberCloner $cloner,
        BlacklistMatcher $blacklist,
        int $customerId,
    ): void {
        $source->subscribers()
            ->with(['fieldValues', 'list'])
            ->whereNotIn('status', array_map(
                static fn (SubscriberStatus $status): string => $status->value,
                self::NEVER_MERGED,
            ))
            ->orderBy('id')
            ->chunkById(500, function ($subscribers) use ($target, $cloner, $blacklist, $customerId): void {
                foreach ($subscribers as $subscriber) {
                    if ($blacklist->matches((string) $subscriber->email, $customerId)) {
                        continue;
                    }

                    if ($cloner->cloneTo($subscriber, $target) !== null) {
                        continue;
                    }

                    // Duplicate: the email already lives in the target list.
                    if ($this->duplicateHandling === self::DUPLICATES_UPDATE) {
                        $this->updateExisting($subscriber, $target);
                    }
                }
            });
    }

    /** Copy the source row's mapped field values onto the existing target row. */
    private function updateExisting(Subscriber $subscriber, EmailList $target): void
    {
        /** @var Subscriber|null $existing */
        $existing = Subscriber::withoutTenancy()
            ->where('list_id', $target->id)
            ->where('email', $subscriber->email)
            ->first();

        if ($existing === null) {
            return;
        }

        $sourceTags = $subscriber->list?->fields()->pluck('tag', 'id') ?? collect();
        $targetIds = $target->fields()->pluck('id', 'tag');

        foreach ($subscriber->fieldValues as $value) {
            $tag = $sourceTags->get($value->list_field_id);
            $targetFieldId = $tag === null ? null : $targetIds->get($tag);

            if ($targetFieldId === null) {
                continue; // no counterpart on the target → skip, never invent
            }

            SubscriberFieldValue::withoutTenancy()->updateOrCreate(
                ['subscriber_id' => $existing->id, 'list_field_id' => (int) $targetFieldId],
                ['value' => $value->value, 'customer_id' => $existing->customer_id],
            );
        }
    }

    public function idempotencyKey(): string
    {
        return 'list-merge:'.$this->operationId;
    }
}
