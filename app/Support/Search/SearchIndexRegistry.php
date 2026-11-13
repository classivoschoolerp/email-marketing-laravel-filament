<?php

declare(strict_types=1);

namespace App\Support\Search;

use InvalidArgumentException;

/**
 * Driver registry for search engines (spec §0.1 F-0.1-008, docs/ARCHITECTURE.md rule 2,
 * WP-42). Registered as a singleton in AppServiceProvider; a plugin adds an
 * engine with `extend()` and never touches core.
 */
class SearchIndexRegistry
{
    /** @var array<string, SearchIndex> */
    private array $engines = [];

    public function __construct()
    {
        foreach ([new DatabaseSearchIndex, new MeilisearchIndex, new TypesenseIndex] as $engine) {
            $this->extend($engine);
        }
    }

    public function extend(SearchIndex $engine): void
    {
        $this->engines[$engine->key()] = $engine;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->engines);
    }

    public function get(string $key): SearchIndex
    {
        if (! isset($this->engines[$key])) {
            throw new InvalidArgumentException('Unknown search engine ['.$key.'].');
        }

        return $this->engines[$key];
    }

    /**
     * The configured engine. An unknown driver name degrades to `database`
     * (= no engine) rather than throwing: a typo in .env must not take the
     * subscriber index down.
     */
    public function active(): SearchIndex
    {
        $key = (string) config('emp-search.driver', 'database');

        return $this->engines[$key] ?? $this->engines['database'];
    }

    /** Fully-qualified index name for a logical index, with the config prefix. */
    public function indexName(string $logical): string
    {
        return (string) config('emp-search.prefix', 'emp_').$logical;
    }
}
