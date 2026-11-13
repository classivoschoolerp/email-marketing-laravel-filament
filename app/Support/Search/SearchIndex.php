<?php

declare(strict_types=1);

namespace App\Support\Search;

/**
 * A search engine this platform can index into and query (spec §0.1
 * F-0.1-008, WP-42).
 *
 * The docs/ARCHITECTURE.md rule-2 contract: implement it, register the key in
 * {@see SearchIndexRegistry}, and nothing in the application changes.
 * Deliberately id-only on the read side — the engine answers "which rows",
 * the database still answers "what is in them", so tenancy scopes, policies
 * and eager loads keep doing their job on a normal Eloquent query.
 */
interface SearchIndex
{
    /** Stable key used in config('emp-search.driver'). */
    public function key(): string;

    /**
     * Is this engine usable right now? A false here means every caller
     * silently falls back to SQL — never an error page because a search
     * server is down.
     */
    public function available(): bool;

    /**
     * Upsert documents. Each document MUST carry an `id` key.
     *
     * @param  list<array<string, mixed>>  $documents
     */
    public function upsert(string $index, array $documents): void;

    /**
     * @param  list<int|string>  $ids
     */
    public function delete(string $index, array $ids): void;

    /** Drop the whole index. */
    public function flush(string $index): void;

    /**
     * @param  array<string, string|int|null>  $filters  equality filters, e.g. customer_id
     * @return list<int> matching document ids, most relevant first
     */
    public function search(string $index, string $term, array $filters = [], int $limit = 1000): array;
}
