<?php

declare(strict_types=1);

namespace App\Support\Search;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Meilisearch engine (spec §0.1 F-0.1-008, WP-42).
 *
 * Speaks the documented v1 HTTP API — the same endpoints
 * `meilisearch/meilisearch-php` (and therefore Laravel Scout) uses, against
 * the same index names, so an install that later adds Scout inherits this
 * index instead of rebuilding it:
 *
 *   PUT    /indexes/{index}/documents        upsert (primaryKey=id)
 *   POST   /indexes/{index}/documents/delete-batch
 *   DELETE /indexes/{index}
 *   POST   /indexes/{index}/search           {q, filter, limit}
 *
 * Every call is wrapped: a search server that is down or slow must degrade
 * the search to SQL, never fail the request.
 */
class MeilisearchIndex implements SearchIndex
{
    public function key(): string
    {
        return 'meilisearch';
    }

    public function available(): bool
    {
        return $this->host() !== '';
    }

    public function upsert(string $index, array $documents): void
    {
        if ($documents === [] || ! $this->available()) {
            return;
        }

        $this->call(fn (PendingRequest $http): mixed => $http
            ->put($this->host().'/indexes/'.$index.'/documents?primaryKey=id', $documents));
    }

    public function delete(string $index, array $ids): void
    {
        if ($ids === [] || ! $this->available()) {
            return;
        }

        $this->call(fn (PendingRequest $http): mixed => $http
            ->post($this->host().'/indexes/'.$index.'/documents/delete-batch', array_values($ids)));
    }

    public function flush(string $index): void
    {
        if (! $this->available()) {
            return;
        }

        $this->call(fn (PendingRequest $http): mixed => $http->delete($this->host().'/indexes/'.$index));
    }

    public function search(string $index, string $term, array $filters = [], int $limit = 1000): array
    {
        if (! $this->available()) {
            return [];
        }

        $payload = ['q' => $term, 'limit' => $limit];

        $expressions = [];

        foreach ($filters as $field => $value) {
            if ($value !== null && $value !== '') {
                $expressions[] = $field.' = '.(is_int($value) ? (string) $value : '"'.$value.'"');
            }
        }

        if ($expressions !== []) {
            $payload['filter'] = implode(' AND ', $expressions);
        }

        $response = $this->call(fn (PendingRequest $http): mixed => $http
            ->post($this->host().'/indexes/'.$index.'/search', $payload));

        if ($response === null) {
            return [];
        }

        return array_values(array_map(
            static fn (array $hit): int => (int) ($hit['id'] ?? 0),
            (array) ($response->json('hits') ?? []),
        ));
    }

    private function host(): string
    {
        return rtrim((string) config('emp-search.meilisearch.host', ''), '/');
    }

    /**
     * @param  callable(PendingRequest): mixed  $call
     */
    private function call(callable $call): mixed
    {
        $http = Http::timeout((int) config('emp-search.timeout', 5))
            ->acceptJson();

        $key = (string) config('emp-search.meilisearch.key', '');

        if ($key !== '') {
            $http = $http->withToken($key);
        }

        try {
            return $call($http);
        } catch (Throwable $e) {
            Log::warning('Meilisearch call failed: '.$e->getMessage());

            return null;
        }
    }
}
