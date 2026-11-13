<?php

declare(strict_types=1);

namespace App\Jobs\Lists;

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

/**
 * Deduplicate-across-lists tool (spec F-2.2.13-006): across N lists of the
 * same account, keep exactly ONE subscriber row per address and remove the
 * rest, as a background job (maintenance queue, idempotent, silent — the
 * WP-08 split/sync shape).
 *
 * The documented winner rule, applied per address:
 *
 *   1. If a master list is given and the address exists in it, the master
 *      list's row survives (lowest id, should the master itself hold more
 *      than one).
 *   2. Otherwise the EARLIEST SUBSCRIBED row survives — `subscribed_at`
 *      ascending, rows without a subscribed_at ranking last, ties broken by
 *      the lowest id (the oldest record).
 *
 * Every other row for that address, in the selected lists only, is soft
 * deleted through the model so cascades and §2.2.14 restore semantics behave
 * as on a manual delete. Lists that are not selected are never touched, and
 * because the list ids are resolved through the tenant-scoped EmailList query
 * (D-05) a foreign list can never take part.
 */
class DeduplicateListsJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    /** @param list<int> $listIds */
    public function __construct(
        public readonly int $customerId,
        public readonly array $listIds,
        public readonly ?int $masterListId,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(CurrentCustomer $current): void
    {
        $customer = Customer::query()->find($this->customerId);

        if ($customer === null) {
            return;
        }

        $current->runAs($customer, function (): void {
            // Tenant-scoped resolution: foreign list ids drop out here.
            $listIds = EmailList::query()
                ->whereIn('id', array_values(array_unique(array_map(intval(...), $this->listIds))))
                ->orderBy('id')
                ->pluck('id')
                ->map(intval(...))
                ->all();

            if (count($listIds) < 2) {
                return; // nothing to compare across
            }

            $master = $this->masterListId !== null && in_array($this->masterListId, $listIds, true)
                ? $this->masterListId
                : null;

            // Ordered by address so each address's rows arrive contiguously
            // and only one group is ever held in memory.
            $cursor = Subscriber::query()
                ->whereIn('list_id', $listIds)
                ->orderBy('email')
                ->orderBy('id')
                ->cursor();

            $group = [];
            $key = null;

            foreach ($cursor as $subscriber) {
                $email = mb_strtolower(trim((string) $subscriber->email));

                if ($key !== null && $email !== $key) {
                    $this->resolveGroup($group, $master);
                    $group = [];
                }

                $key = $email;
                $group[] = $subscriber;
            }

            $this->resolveGroup($group, $master);
        });
    }

    /** @param list<Subscriber> $group */
    private function resolveGroup(array $group, ?int $master): void
    {
        if (count($group) < 2) {
            return;
        }

        $winner = $this->winner($group, $master);

        foreach ($group as $subscriber) {
            if ((int) $subscriber->id !== (int) $winner->id) {
                $subscriber->delete();
            }
        }
    }

    /** @param list<Subscriber> $group */
    private function winner(array $group, ?int $master): Subscriber
    {
        if ($master !== null) {
            $inMaster = array_values(array_filter(
                $group,
                static fn (Subscriber $subscriber): bool => (int) $subscriber->list_id === $master,
            ));

            if ($inMaster !== []) {
                return $this->lowestId($inMaster);
            }
        }

        $earliest = null;

        foreach ($group as $subscriber) {
            if ($subscriber->subscribed_at === null) {
                continue;
            }

            if ($earliest === null
                || $subscriber->subscribed_at->lt($earliest->subscribed_at)
                || ($subscriber->subscribed_at->eq($earliest->subscribed_at) && $subscriber->id < $earliest->id)) {
                $earliest = $subscriber;
            }
        }

        return $earliest ?? $this->lowestId($group);
    }

    /** @param list<Subscriber> $group */
    private function lowestId(array $group): Subscriber
    {
        $lowest = $group[0];

        foreach ($group as $subscriber) {
            if ((int) $subscriber->id < (int) $lowest->id) {
                $lowest = $subscriber;
            }
        }

        return $lowest;
    }

    public function idempotencyKey(): string
    {
        return 'list-dedupe:'.$this->operationId;
    }
}
