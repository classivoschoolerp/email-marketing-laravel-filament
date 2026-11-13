<?php

declare(strict_types=1);

namespace App\Support\Search;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Typesense engine (spec §0.1 F-0.1-008, WP-42).
 *
 * Speaks the documented v1 HTTP API — the same endpoints
 * `typesense/typesense-php` (and therefore Laravel Scout) uses:
 *
 *   POST   /collections/{c}/documents/import?action=upsert   JSONL body
 *   DELETE /collections/{c}/documents?filter_by=id:[...]
 *   DELETE /collections/{c}
 *   GET    /collections/{c}/documents/search?q=&query_by=&filter_by=&per_page=
 *
 * Typesense ids are strings; the platform's are integers, so ids are cast on
 * the way in and back out. Failures degrade to SQL exactly as Meilisearch's do.
 */
class TypesenseIndex implements SearchIndex
{
    /** Fields the subscriber index is searched on. */
    public const QUERY_BY = 'email,fields';

    public function key(): string
    {
        return 'typesense';
    }

    public function available(): bool
    {
        return $this->host() !== '' && $this->apiKey() !== '';
    }

    public function upsert(string $index, array $documents): void
    {
        if ($documents === [] || ! $this->available()) {
            return;
        }

        $lines = [];

        foreach ($documents as $document) {
            $document['id'] = (string) ($document['id'] ?? '');
            $lines[] = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $this->call(fn (PendingRequest $http): mixed => $http
            ->withBody(implode("\n", $lines), 'text/plain')
            ->post($this->host().'/collections/'.$index.'/documents/import?action=upsert'));
    }

    public function delete(string $index, array $ids): void
    {
        if ($ids === [] || ! $this->available()) {
            return;
        }

        $filter = 'id:['.implode(',', array_map(static fn (int|string $id): string => (string) $id, $ids)).']';

        $this->call(fn (PendingRequest $http): mixed => $http
            ->delete($this->host().'/collections/'.$index.'/documents', ['filter_by' => $filter]));
    }

    public function flush(string $index): void
    {
        if (! $this->available()) {
            return;
        }

        $this->call(fn (PendingRequest $http): mixed => $http->delete($this->host().'/collections/'.$index));
    }

    public function search(string $index, string $term, array $filters = [], int $limit = 1000): array
    {
        if (! $this->available()) {
            return [];
        }

        $query = [
            'q' => $term === '' ? '*' : $term,
            'query_by' => self::QUERY_BY,
            'per_page' => min($limit, 250),
        ];

        $expressions = [];

        foreach ($filters as $field => $value) {
            if ($value !== null && $value !== '') {
                $expressions[] = $field.':='.$value;
            }
        }

        if ($expressions !== []) {
            $query['filter_by'] = implode(' && ', $expressions);
        }

        $response = $this->call(fn (PendingRequest $http): mixed => $http
            ->get($this->host().'/collections/'.$index.'/documents/search', $query));

        if ($response === null) {
            return [];
        }

        return array_values(array_map(
            static fn (array $hit): int => (int) ($hit['document']['id'] ?? 0),
            (array) ($response->json('hits') ?? []),
        ));
    }

    private function host(): string
    {
        return rtrim((string) config('emp-search.typesense.host', ''), '/');
    }

    private function apiKey(): string
    {
        return (string) config('emp-search.typesense.key', '');
    }

    /**
     * @param  callable(PendingRequest): mixed  $call
     */
    private function call(callable $call): mixed
    {
        $http = Http::timeout((int) config('emp-search.timeout', 5))
            ->acceptJson()
            ->withHeaders(['X-TYPESENSE-API-KEY' => $this->apiKey()]);

        try {
            return $call($http);
        } catch (Throwable $e) {
            Log::warning('Typesense call failed: '.$e->getMessage());

            return null;
        }
    }
}
