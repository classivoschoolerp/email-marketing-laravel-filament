<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Search engines for subscriber & contact search at scale (spec §0.1 F-0.1-008)
|--------------------------------------------------------------------------
| The customer subscriber index searches email AND every custom field value
| with `LIKE %term%` — correct, and unusable past a few million rows because
| a leading wildcard cannot use an index. This is the seam that replaces it
| with a real search engine.
|
| Shape: the docs/ARCHITECTURE.md rule-2 driver registry, exactly like sending drivers
| and verification services, so a new engine is a class and a config line and
| never a core change.
|
| RELATIONSHIP TO LARAVEL SCOUT — read this before changing anything:
| `laravel/scout` is NOT installed in this repository and cannot be added
| here (no network/composer access on the build machine). The keys below are
| deliberately the same shape as Scout's meilisearch/typesense config, and
| the drivers speak the same HTTP APIs against the same index names, so an
| installation that later runs `composer require laravel/scout` +
| `meilisearch/meilisearch-php` or `typesense/typesense-php` can point Scout
| at the identical hosts/keys/indexes and swap `SearchIndexRegistry` for
| Scout's engine manager without reindexing. See docs/search.md.
|
| `database` (the default) means "no engine": every caller falls back to the
| existing SQL behaviour, so an install that configures nothing behaves
| exactly as it did before this shipped.
*/

return [

    'driver' => env('EMP_SEARCH_DRIVER', 'database'),

    /** Prefix for every index/collection name, so several installs can share one engine. */
    'prefix' => env('EMP_SEARCH_PREFIX', 'emp_'),

    /** Documents pushed per request when (re)indexing. */
    'chunk' => (int) env('EMP_SEARCH_CHUNK', 500),

    /** Hits requested from the engine for one search. */
    'limit' => (int) env('EMP_SEARCH_LIMIT', 1000),

    /** Seconds before an engine call is abandoned and the caller falls back to SQL. */
    'timeout' => (int) env('EMP_SEARCH_TIMEOUT', 5),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
    ],

    'typesense' => [
        'host' => env('TYPESENSE_HOST', 'http://localhost:8108'),
        'key' => env('TYPESENSE_API_KEY'),
    ],

];
