<?php

declare(strict_types=1);

namespace App\Services\Subscribers;

use App\Models\ListField;
use App\Models\Subscriber;
use App\Support\Search\SearchIndexRegistry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Subscriber & contact search at scale (spec §0.1 F-0.1-008, WP-42).
 *
 * The customer subscriber index searches email AND every custom field value
 * with `LIKE %term%`; the leading wildcard means no index can be used and
 * the query degrades linearly with the table. This service is the seam that
 * hands that query to a real engine when one is configured, and keeps the
 * SQL behaviour byte-for-byte when one is not.
 *
 * The engine only ever returns IDS. The caller then narrows a normal
 * Eloquent query to those ids, so the D-05 tenancy scope, the policies and
 * every eager load keep applying — an engine can never widen what a customer
 * can see, only pre-filter it. The `customer_id` filter is passed to the
 * engine as well, so a shared engine is fenced at both ends.
 */
class SubscriberSearchIndexer
{
    public const INDEX = 'subscribers';

    public function __construct(private readonly SearchIndexRegistry $registry) {}

    public function enabled(): bool
    {
        return $this->registry->active()->available();
    }

    public function indexName(): string
    {
        return $this->registry->indexName(self::INDEX);
    }

    /**
     * The searchable document for one subscriber: the address plus every
     * custom-field value flattened into one blob, which is exactly what the
     * SQL path searches today.
     *
     * @return array<string, mixed>
     */
    public function document(Subscriber $subscriber): array
    {
        $values = $subscriber->relationLoaded('fieldValues')
            ? $subscriber->fieldValues
            : $subscriber->fieldValues()->get();

        return [
            'id' => (int) $subscriber->id,
            'customer_id' => (int) $subscriber->customer_id,
            'list_id' => (int) $subscriber->list_id,
            'email' => (string) $subscriber->email,
            'status' => $subscriber->status->value,
            'fields' => trim(implode(' ', array_filter(
                $values->pluck('value')->map(fn (mixed $v): string => (string) $v)->all(),
                static fn (string $v): bool => $v !== '',
            ))),
        ];
    }

    /** @param iterable<Subscriber> $subscribers */
    public function upsert(iterable $subscribers): int
    {
        $engine = $this->registry->active();

        if (! $engine->available()) {
            return 0;
        }

        $documents = [];

        foreach ($subscribers as $subscriber) {
            $documents[] = $this->document($subscriber);
        }

        if ($documents === []) {
            return 0;
        }

        $engine->upsert($this->indexName(), $documents);

        return count($documents);
    }

    /** @param list<int> $ids */
    public function forget(array $ids): void
    {
        $this->registry->active()->delete($this->indexName(), $ids);
    }

    public function flush(): void
    {
        $this->registry->active()->flush($this->indexName());
    }

    /**
     * Engine-backed id list for a search term, or NULL when no engine is
     * configured/reachable — the caller then keeps its SQL path. An engine
     * that is up but matches nothing returns an empty array, which is a real
     * "no results", not a fallback.
     *
     * @return list<int>|null
     */
    public function ids(string $term, ?int $customerId = null): ?array
    {
        $engine = $this->registry->active();

        if (! $engine->available()) {
            return null;
        }

        return $engine->search(
            $this->indexName(),
            $term,
            $customerId === null ? [] : ['customer_id' => $customerId],
            (int) config('emp-search.limit', 1000),
        );
    }

    /**
     * Apply a search term to a subscriber query — engine first, SQL
     * otherwise. This is the ONE place that decides, so the Filament table,
     * the admin overview and any future caller cannot diverge.
     *
     * @param  Builder<Subscriber>  $query
     * @return Builder<Subscriber>
     */
    public function apply(Builder $query, string $term, ?int $customerId = null): Builder
    {
        $ids = $this->ids($term, $customerId);

        if ($ids !== null) {
            return $query->whereIn('subscribers.id', $ids);
        }

        return $query->where(
            fn (Builder $q): Builder => $q
                ->where('subscribers.email', 'like', '%'.$term.'%')
                ->orWhereHas('fieldValues', fn (Builder $fq): Builder => $fq->where('value', 'like', '%'.$term.'%')),
        );
    }

    /** Field ids of a list, for callers that need the searchable field set. */
    public function searchableFieldIds(int $listId): array
    {
        return ListField::withoutTenancy()->where('list_id', $listId)->pluck('id')->all();
    }
}
