<?php

declare(strict_types=1);

namespace App\Support\Search;

/**
 * The default "engine": none (spec §0.1 F-0.1-008, WP-42).
 *
 * A null object rather than a branch at every call site — `available()` is
 * false, so every caller keeps doing exactly what it did before an engine
 * existed (SQL `LIKE`). Writes are accepted and discarded so indexing jobs
 * and observers need no driver check of their own.
 */
class DatabaseSearchIndex implements SearchIndex
{
    public function key(): string
    {
        return 'database';
    }

    public function available(): bool
    {
        return false;
    }

    public function upsert(string $index, array $documents): void {}

    public function delete(string $index, array $ids): void {}

    public function flush(string $index): void {}

    public function search(string $index, string $term, array $filters = [], int $limit = 1000): array
    {
        return [];
    }
}
