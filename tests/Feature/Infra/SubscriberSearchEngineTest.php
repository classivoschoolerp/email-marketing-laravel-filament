<?php

declare(strict_types=1);

use App\Jobs\Subscribers\ReindexSubscribersJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Services\Subscribers\SubscriberSearchIndexer;
use App\Support\Search\DatabaseSearchIndex;
use App\Support\Search\MeilisearchIndex;
use App\Support\Search\SearchIndexRegistry;
use App\Support\Search\TypesenseIndex;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * WP-42 — F-0.1-008 "Scout + Meilisearch/Typesense — subscriber & contact
 * search at scale".
 *
 * `laravel/scout` is NOT installed and cannot be added on this machine
 * (no composer access), so what ships is the driver registry the codebase
 * mandates (docs/ARCHITECTURE.md rule 2) speaking the SAME HTTP APIs, index names and
 * config keys Scout's meilisearch/typesense engines use — see
 * config/emp-search.php and docs/search.md for the exact migration path.
 * These tests pin the contract with Http::fake, and pin that an install with
 * no engine behaves EXACTLY as it did before.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->field = ListField::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'tag' => 'FNAME',
    ]);
});

afterEach(fn () => app(CurrentCustomer::class)->set(null));

function searchSubscriber(string $email, ?string $fieldValue = null): Subscriber
{
    $subscriber = Subscriber::factory()->create([
        'customer_id' => test()->customer->id,
        'list_id' => test()->list->id,
        'email' => $email,
    ]);

    if ($fieldValue !== null) {
        SubscriberFieldValue::withoutTenancy()->create([
            'customer_id' => test()->customer->id,
            'subscriber_id' => $subscriber->id,
            'list_field_id' => test()->field->id,
            'value' => $fieldValue,
        ]);
    }

    return $subscriber;
}

/* ---------------------------------------------- registry */

it('registers the database, meilisearch and typesense engines (F-0.1-008)', function (): void {
    $registry = app(SearchIndexRegistry::class);

    expect($registry->keys())->toBe(['database', 'meilisearch', 'typesense'])
        ->and($registry->get('meilisearch'))->toBeInstanceOf(MeilisearchIndex::class)
        ->and($registry->get('typesense'))->toBeInstanceOf(TypesenseIndex::class);

    // Default install: no engine.
    expect($registry->active())->toBeInstanceOf(DatabaseSearchIndex::class)
        ->and($registry->active()->available())->toBeFalse();

    // A typo in .env degrades to "no engine" instead of taking search down.
    config(['emp-search.driver' => 'nope']);
    expect(app(SearchIndexRegistry::class)->active())->toBeInstanceOf(DatabaseSearchIndex::class);
});

it('prefixes index names so installs can share one engine (F-0.1-008)', function (): void {
    config(['emp-search.prefix' => 'acme_']);

    expect(app(SearchIndexRegistry::class)->indexName('subscribers'))->toBe('acme_subscribers')
        ->and(app(SubscriberSearchIndexer::class)->indexName())->toBe('acme_subscribers');
});

/* ---------------------------------------------- no engine = old behaviour */

it('falls back to the SQL search when no engine is configured (F-0.1-008)', function (): void {
    Http::fake();

    searchSubscriber('ada@example.test', 'Ada');
    searchSubscriber('bob@example.test', 'Bob');

    $indexer = app(SubscriberSearchIndexer::class);

    expect($indexer->enabled())->toBeFalse()
        ->and($indexer->ids('ada'))->toBeNull();

    // Both halves of the original LIKE search still work: address and field.
    expect($indexer->apply(Subscriber::query(), 'ada')->pluck('email')->all())->toBe(['ada@example.test'])
        ->and($indexer->apply(Subscriber::query(), 'Bob')->pluck('email')->all())->toBe(['bob@example.test']);

    Http::assertNothingSent();
});

/* ---------------------------------------------- meilisearch */

it('searches Meilisearch with the documented request shape (F-0.1-008)', function (): void {
    config([
        'emp-search.driver' => 'meilisearch',
        'emp-search.prefix' => 'emp_',
        'emp-search.meilisearch.host' => 'http://meili.test:7700',
        'emp-search.meilisearch.key' => 'master-key',
        'emp-search.limit' => 250,
    ]);

    $ada = searchSubscriber('ada@example.test', 'Ada');
    searchSubscriber('bob@example.test', 'Bob');

    Http::fake([
        '*/indexes/emp_subscribers/search' => Http::response([
            'hits' => [['id' => $ada->id]],
        ]),
        '*' => Http::response(['taskUid' => 1]),
    ]);

    $indexer = app(SubscriberSearchIndexer::class);

    expect($indexer->enabled())->toBeTrue()
        ->and($indexer->ids('ada', (int) $this->customer->id))->toBe([(int) $ada->id])
        ->and($indexer->apply(Subscriber::query(), 'ada', (int) $this->customer->id)->pluck('email')->all())
        ->toBe(['ada@example.test']);

    Http::assertSent(function ($request) use ($ada): bool {
        return $request->url() === 'http://meili.test:7700/indexes/emp_subscribers/search'
            && $request->method() === 'POST'
            && $request->header('Authorization') === ['Bearer master-key']
            && $request['q'] === 'ada'
            && $request['limit'] === 250
            && $request['filter'] === 'customer_id = '.$this->customer->id
            && $ada->id > 0;
    });
});

it('upserts Meilisearch documents with the email and every field value (F-0.1-008)', function (): void {
    config([
        'emp-search.driver' => 'meilisearch',
        'emp-search.meilisearch.host' => 'http://meili.test:7700',
        'emp-search.meilisearch.key' => 'master-key',
    ]);

    Http::fake(['*' => Http::response(['taskUid' => 1])]);

    $ada = searchSubscriber('ada@example.test', 'Ada');

    $document = app(SubscriberSearchIndexer::class)->document($ada->fresh());

    expect($document)->toBe([
        'id' => (int) $ada->id,
        'customer_id' => (int) $this->customer->id,
        'list_id' => (int) $this->list->id,
        'email' => 'ada@example.test',
        'status' => $ada->status->value,
        'fields' => 'Ada',
    ]);

    app(SubscriberSearchIndexer::class)->upsert([$ada->fresh()]);

    Http::assertSent(function ($request) use ($document): bool {
        return $request->url() === 'http://meili.test:7700/indexes/emp_subscribers/documents?primaryKey=id'
            && $request->method() === 'PUT'
            && $request->data() === [$document];
    });
});

it('removes deleted subscribers from Meilisearch (F-0.1-008)', function (): void {
    config([
        'emp-search.driver' => 'meilisearch',
        'emp-search.meilisearch.host' => 'http://meili.test:7700',
    ]);

    Http::fake(['*' => Http::response(['taskUid' => 1])]);

    app(SubscriberSearchIndexer::class)->forget([11, 22]);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://meili.test:7700/indexes/emp_subscribers/documents/delete-batch'
        && $request->method() === 'POST'
        && $request->data() === [11, 22]);
});

/* ---------------------------------------------- typesense */

it('searches Typesense with the documented request shape (F-0.1-008)', function (): void {
    config([
        'emp-search.driver' => 'typesense',
        'emp-search.typesense.host' => 'http://typesense.test:8108',
        'emp-search.typesense.key' => 'ts-key',
    ]);

    $ada = searchSubscriber('ada@example.test', 'Ada');

    Http::fake([
        '*/collections/emp_subscribers/documents/search*' => Http::response([
            'hits' => [['document' => ['id' => (string) $ada->id]]],
        ]),
        '*' => Http::response([]),
    ]);

    expect(app(SubscriberSearchIndexer::class)->ids('ada', (int) $this->customer->id))
        ->toBe([(int) $ada->id]);

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'http://typesense.test:8108/collections/emp_subscribers/documents/search')
            && $request->method() === 'GET'
            && $request->header('X-TYPESENSE-API-KEY') === ['ts-key']
            && ($query['q'] ?? null) === 'ada'
            && ($query['query_by'] ?? null) === TypesenseIndex::QUERY_BY
            && ($query['filter_by'] ?? null) === 'customer_id:='.$this->customer->id;
    });
});

it('imports Typesense documents as upsert JSONL (F-0.1-008)', function (): void {
    config([
        'emp-search.driver' => 'typesense',
        'emp-search.typesense.host' => 'http://typesense.test:8108',
        'emp-search.typesense.key' => 'ts-key',
    ]);

    Http::fake(['*' => Http::response([])]);

    $ada = searchSubscriber('ada@example.test', 'Ada');
    $bob = searchSubscriber('bob@example.test', 'Bob');

    app(SubscriberSearchIndexer::class)->upsert([$ada->fresh(), $bob->fresh()]);

    Http::assertSent(function ($request) use ($ada, $bob): bool {
        if ($request->url() !== 'http://typesense.test:8108/collections/emp_subscribers/documents/import?action=upsert') {
            return false;
        }

        $lines = explode("\n", (string) $request->body());

        return count($lines) === 2
            && json_decode($lines[0], true)['id'] === (string) $ada->id
            && json_decode($lines[0], true)['fields'] === 'Ada'
            && json_decode($lines[1], true)['id'] === (string) $bob->id;
    });
});

it('needs an api key before Typesense is considered available (F-0.1-008)', function (): void {
    config([
        'emp-search.driver' => 'typesense',
        'emp-search.typesense.host' => 'http://typesense.test:8108',
        'emp-search.typesense.key' => null,
    ]);

    Http::fake();

    expect(app(SubscriberSearchIndexer::class)->enabled())->toBeFalse()
        ->and(app(SubscriberSearchIndexer::class)->ids('ada'))->toBeNull();

    Http::assertNothingSent();
});

/* ---------------------------------------------- tenancy + bulk indexing */

it('fences an engine result to the tenant on both sides (F-0.1-008)', function (): void {
    config([
        'emp-search.driver' => 'meilisearch',
        'emp-search.meilisearch.host' => 'http://meili.test:7700',
    ]);

    $mine = searchSubscriber('ada@example.test', 'Ada');

    $other = Customer::factory()->create();
    $otherList = EmailList::factory()->create(['customer_id' => $other->id]);
    $theirs = Subscriber::factory()->create([
        'customer_id' => $other->id,
        'list_id' => $otherList->id,
        'email' => 'ada@rival.test',
    ]);

    // A misbehaving engine returns BOTH ids; the Eloquent scope still wins.
    Http::fake([
        '*/indexes/*/search' => Http::response([
            'hits' => [['id' => $mine->id], ['id' => $theirs->id]],
        ]),
        '*' => Http::response([]),
    ]);

    expect(app(SubscriberSearchIndexer::class)
        ->apply(Subscriber::query(), 'ada', (int) $this->customer->id)
        ->pluck('email')->all())->toBe(['ada@example.test']);
});

it('queues the bulk reindex instead of running it inline (F-0.1-008)', function (): void {
    config([
        'emp-search.driver' => 'meilisearch',
        'emp-search.meilisearch.host' => 'http://meili.test:7700',
    ]);

    Queue::fake();

    $this->artisan('emp:search-reindex', ['--customer' => (string) $this->customer->id])
        ->assertExitCode(0);

    Queue::assertPushed(
        ReindexSubscribersJob::class,
        fn (ReindexSubscribersJob $job): bool => $job->customerId === (int) $this->customer->id
            && $job->flushFirst === false,
    );
});

it('indexes every subscriber when the reindex job runs (F-0.1-008)', function (): void {
    // Created with NO engine configured, so nothing is indexed as they are
    // written — this is the backfill case the command exists for.
    searchSubscriber('ada@example.test', 'Ada');
    searchSubscriber('bob@example.test', 'Bob');

    config([
        'emp-search.driver' => 'meilisearch',
        'emp-search.meilisearch.host' => 'http://meili.test:7700',
        'emp-search.chunk' => 1,
    ]);

    Http::fake(['*' => Http::response(['taskUid' => 1])]);

    $indexed = (new ReindexSubscribersJob((int) $this->customer->id, true))
        ->handle(app(SubscriberSearchIndexer::class));

    expect($indexed)->toBe(2);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://meili.test:7700/indexes/emp_subscribers'
        && $request->method() === 'DELETE');

    Http::assertSentCount(3); // 1 flush + 2 chunked upserts
});

it('keeps the index fresh on subscriber writes through the observer (F-0.1-008)', function (): void {
    config([
        'emp-search.driver' => 'meilisearch',
        'emp-search.meilisearch.host' => 'http://meili.test:7700',
    ]);

    Http::fake(['*' => Http::response(['taskUid' => 1])]);

    $ada = searchSubscriber('ada@example.test', 'Ada');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://meili.test:7700/indexes/emp_subscribers/documents?primaryKey=id'
        && $request->method() === 'PUT'
        && $request->data()[0]['id'] === (int) $ada->id);

    $ada->forceDelete();

    Http::assertSent(fn ($request): bool => $request->url() === 'http://meili.test:7700/indexes/emp_subscribers/documents/delete-batch'
        && $request->data() === [(int) $ada->id]);
});

it('leaves subscriber writes alone when no engine is configured (F-0.1-008)', function (): void {
    Http::fake();

    $ada = searchSubscriber('ada@example.test', 'Ada');
    $ada->forceDelete();

    Http::assertNothingSent();
});

it('does nothing when reindexing with no engine configured (F-0.1-008)', function (): void {
    Http::fake();

    searchSubscriber('ada@example.test', 'Ada');

    expect((new ReindexSubscribersJob)->handle(app(SubscriberSearchIndexer::class)))->toBe(0);

    $this->artisan('emp:search-reindex')
        ->expectsOutputToContain(__('ops.search.no_engine', ['driver' => 'database']))
        ->assertExitCode(0);

    Http::assertNothingSent();
});
