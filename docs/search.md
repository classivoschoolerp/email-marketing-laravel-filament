# Subscriber & contact search at scale

Spec §0.1 F-0.1-008 — "Scout + Meilisearch/Typesense". Shipped in WP-42.

## The problem this solves

The customer subscriber index searches the address **and every custom field
value**:

```sql
where subscribers.email like '%term%'
   or exists (select 1 from subscriber_field_values where value like '%term%')
```

A leading wildcard cannot use an index, so that query degrades linearly with
the table. It is fine at 100k rows and unusable at 10M.

## What ships

A driver registry (docs/ARCHITECTURE.md rule 2), the same shape as the sending-driver and
verification registries:

| Piece | Class |
|---|---|
| Contract | `App\Support\Search\SearchIndex` |
| Registry | `App\Support\Search\SearchIndexRegistry` |
| No engine (default) | `App\Support\Search\DatabaseSearchIndex` |
| Meilisearch | `App\Support\Search\MeilisearchIndex` |
| Typesense | `App\Support\Search\TypesenseIndex` |
| Subscriber documents + read path | `App\Services\Subscribers\SubscriberSearchIndexer` |
| Index freshness | `App\Observers\SubscriberSearchObserver` |
| Bulk backfill | `App\Jobs\Subscribers\ReindexSubscribersJob`, `emp:search-reindex` |

Config: `config/emp-search.php`.

```dotenv
EMP_SEARCH_DRIVER=meilisearch      # database (default) | meilisearch | typesense
EMP_SEARCH_PREFIX=emp_
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=...
# or
EMP_SEARCH_DRIVER=typesense
TYPESENSE_HOST=http://127.0.0.1:8108
TYPESENSE_API_KEY=...
```

Then backfill:

```bash
php artisan emp:search-reindex --fresh          # queued
php artisan emp:search-reindex --sync           # run now (small installs)
php artisan emp:search-reindex --customer=42    # one tenant
```

## Guarantees

* **`database` is the default and means "no engine".** Every caller keeps the
  exact SQL behaviour it had before this shipped. An unknown driver name in
  `.env` degrades to `database` rather than breaking search.
* **An engine can never widen what a customer sees.** It returns ids only; the
  application then runs a normal Eloquent query, so the D-05 global scope,
  policies and eager loads all still apply. The `customer_id` filter is *also*
  sent to the engine, so a shared engine is fenced at both ends.
* **A search server that is down or slow never breaks a page.** Every engine
  call is wrapped; a failure logs a warning and the caller falls back to SQL.
* **Bulk paths do not fire one HTTP call per row.** The observer handles single
  writes; imports and list tools should dispatch `ReindexSubscribersJob`.

## Relationship to Laravel Scout — read before changing anything

`laravel/scout` is **not installed** in this repository, and it could not be
added: the build machine has no composer/network access, and this work package
was explicitly forbidden from running `composer require`. That is the honest
status of the "Scout" half of the row.

What was done instead of faking it: the config keys, the index names and the
HTTP endpoints above are deliberately **identical to Scout's meilisearch and
typesense engines**. Specifically:

* index/collection name = `config('emp-search.prefix').'subscribers'`, the same
  string Scout would use for a `searchableAs()` of `subscribers` with that
  prefix;
* Meilisearch: `PUT /indexes/{i}/documents?primaryKey=id`,
  `POST /indexes/{i}/documents/delete-batch`, `POST /indexes/{i}/search`;
* Typesense: `POST /collections/{c}/documents/import?action=upsert` (JSONL),
  `GET /collections/{c}/documents/search`;
* document shape `{id, customer_id, list_id, email, status, fields}`.

So the migration to Scout, when an install can run composer, is:

1. `composer require laravel/scout` plus `meilisearch/meilisearch-php` or
   `typesense/typesense-php`;
2. `php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"`
   and point `config/scout.php` at the SAME host/key/prefix;
3. add `Laravel\Scout\Searchable` to `App\Models\Subscriber` with
   `searchableAs()` returning `app(SubscriberSearchIndexer::class)->indexName()`
   and `toSearchableArray()` returning
   `app(SubscriberSearchIndexer::class)->document($this)`;
4. drop `SubscriberSearchObserver` (Scout's own observer replaces it) and point
   `SubscriberSearchIndexer::ids()` at `Subscriber::search($term)->keys()`.

**No reindex is required** — the existing index is already in Scout's shape.

## Not covered

* Contacts other than subscribers (campaigns, templates, customers) are not
  indexed. The registry is generic (`indexName(string $logical)`), so adding
  one is a document method and a job, not a core change.
* Engine schema/settings management (Meilisearch `filterableAttributes`,
  Typesense collection creation) is an operator step; both engines accept the
  documents above once `customer_id` and `list_id` are filterable. Typesense
  needs the collection created with `email` and `fields` as `string` fields —
  see `TypesenseIndex::QUERY_BY`.
* No live verification: there is no Meilisearch or Typesense to connect to on
  the build machine, so every request/response contract is pinned with
  `Http::fake()` in `tests/Feature/Infra/SubscriberSearchEngineTest.php` and
  belongs on `docs/e2e-checklist.md` for an environment that has one.
