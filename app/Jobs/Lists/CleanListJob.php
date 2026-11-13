<?php

declare(strict_types=1);

namespace App\Jobs\Lists;

use App\Enums\BounceType;
use App\Enums\SubscriberStatus;
use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Verification\Providers\BuiltInVerifier;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Clean-list tool (spec F-2.2.13-004): removes the dead weight from one list
 * as a background job, modelled on the WP-08 split/sync tools (maintenance
 * queue, idempotent, silent). Each rule is individually selectable and they
 * run in a fixed order; a subscriber removed by an earlier rule is not
 * examined again.
 *
 *  - hard_bounces  — status `bounced` with bounce_type `hard`, or any
 *                    campaign_bounce_logs row of type `hard` (§2.9.4).
 *  - unconfirmed   — status `unconfirmed` older than `unconfirmedDays` days
 *                    (measured on subscribed_at, falling back to created_at).
 *                    0 disables the rule, mirroring the D-23 convention on
 *                    the list's own autodelete_unconfirmed_days setting.
 *  - duplicates    — more than one live row with the same case-insensitively
 *                    normalized address in this list; the LOWEST id survives
 *                    (the earliest subscription wins). The (list_id, email)
 *                    unique index makes this unreachable on a
 *                    case-insensitive collation, so the rule is defensive:
 *                    it catches rows written past the model (raw inserts,
 *                    case-differing updates on a binary collation).
 *  - role_addresses — info@, admin@, … detected by
 *                    {@see BuiltInVerifier::isRoleAccount()}, the same
 *                    authority the built-in verification engine uses (D-12).
 *
 * Removal is the model soft delete, so the §2.2.14 purge/restore semantics
 * and the subscriber-action cascades behave exactly as on a manual delete.
 */
class CleanListJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public const RULE_HARD_BOUNCES = 'hard_bounces';

    public const RULE_UNCONFIRMED = 'unconfirmed';

    public const RULE_DUPLICATES = 'duplicates';

    public const RULE_ROLE_ADDRESSES = 'role_addresses';

    /** @return list<string> */
    public const RULES = [
        self::RULE_HARD_BOUNCES,
        self::RULE_UNCONFIRMED,
        self::RULE_DUPLICATES,
        self::RULE_ROLE_ADDRESSES,
    ];

    public int $timeout = 3600;

    /** @param list<string> $rules */
    public function __construct(
        public readonly int $customerId,
        public readonly int $listId,
        public readonly array $rules,
        public readonly int $unconfirmedDays,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(CurrentCustomer $current): void
    {
        $selected = array_values(array_intersect(self::RULES, $this->rules));

        if ($selected === []) {
            return;
        }

        $customer = Customer::query()->find($this->customerId);

        if ($customer === null) {
            return;
        }

        $current->runAs($customer, function () use ($selected): void {
            // Tenancy is the global scope (D-05): a foreign list id does not
            // resolve, so a foreign list can never be cleaned.
            /** @var EmailList|null $list */
            $list = EmailList::query()->find($this->listId);

            if ($list === null) {
                return;
            }

            foreach ($selected as $rule) {
                match ($rule) {
                    self::RULE_HARD_BOUNCES => $this->removeHardBounces($list),
                    self::RULE_UNCONFIRMED => $this->removeStaleUnconfirmed($list),
                    self::RULE_DUPLICATES => $this->removeDuplicates($list),
                    self::RULE_ROLE_ADDRESSES => $this->removeRoleAddresses($list),
                    default => null,
                };
            }
        });
    }

    private function removeHardBounces(EmailList $list): void
    {
        $this->deleteEach(
            $list->subscribers()
                ->where(function (Builder $query): void {
                    $query
                        ->where(fn (Builder $q) => $q
                            ->where('status', SubscriberStatus::Bounced->value)
                            ->where('bounce_type', 'hard'))
                        ->orWhereExists(fn ($sub) => $sub
                            ->select('id')
                            ->from('campaign_bounce_logs')
                            ->whereColumn('campaign_bounce_logs.subscriber_id', 'subscribers.id')
                            ->where('campaign_bounce_logs.bounce_type', BounceType::Hard->value));
                })
        );
    }

    private function removeStaleUnconfirmed(EmailList $list): void
    {
        if ($this->unconfirmedDays < 1) {
            return; // 0 disables the age threshold (D-23 convention)
        }

        $cutoff = now()->subDays($this->unconfirmedDays);

        $this->deleteEach(
            $list->subscribers()
                ->where('status', SubscriberStatus::Unconfirmed->value)
                ->where(fn (Builder $query) => $query
                    ->where('subscribed_at', '<', $cutoff)
                    ->orWhere(fn (Builder $q) => $q->whereNull('subscribed_at')->where('created_at', '<', $cutoff)))
        );
    }

    /** Keep the lowest id per normalized address, remove the rest. */
    private function removeDuplicates(EmailList $list): void
    {
        $seen = [];
        $duplicateIds = [];

        $list->subscribers()
            ->orderBy('id')
            ->select(['id', 'email'])
            ->chunkById(500, function ($subscribers) use (&$seen, &$duplicateIds): void {
                foreach ($subscribers as $subscriber) {
                    $key = mb_strtolower(trim((string) $subscriber->email));

                    if (isset($seen[$key])) {
                        $duplicateIds[] = (int) $subscriber->id;

                        continue;
                    }

                    $seen[$key] = true;
                }
            });

        if ($duplicateIds === []) {
            return;
        }

        $this->deleteEach($list->subscribers()->whereIn('subscribers.id', $duplicateIds));
    }

    private function removeRoleAddresses(EmailList $list): void
    {
        $roleIds = [];

        $list->subscribers()
            ->orderBy('id')
            ->select(['id', 'email'])
            ->chunkById(500, function ($subscribers) use (&$roleIds): void {
                foreach ($subscribers as $subscriber) {
                    if (BuiltInVerifier::isRoleAccount((string) $subscriber->email)) {
                        $roleIds[] = (int) $subscriber->id;
                    }
                }
            });

        if ($roleIds === []) {
            return;
        }

        $this->deleteEach($list->subscribers()->whereIn('subscribers.id', $roleIds));
    }

    /**
     * Delete row by row through the model so soft deletes, cascades and
     * observers all fire (a mass query-builder delete fires no model events).
     *
     * @param  Builder<Subscriber>|\Illuminate\Database\Eloquent\Relations\Relation<Subscriber>  $query
     */
    private function deleteEach(mixed $query): void
    {
        $query->chunkById(500, function ($subscribers): void {
            foreach ($subscribers as $subscriber) {
                $subscriber->delete();
            }
        });
    }

    public function idempotencyKey(): string
    {
        return 'list-clean:'.$this->operationId;
    }
}
