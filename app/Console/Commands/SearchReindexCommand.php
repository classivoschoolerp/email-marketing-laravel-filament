<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Subscribers\ReindexSubscribersJob;
use App\Services\Subscribers\SubscriberSearchIndexer;
use App\Support\Search\SearchIndexRegistry;
use Illuminate\Console\Command;

/**
 * `emp:search-reindex` (spec §0.1 F-0.1-008, WP-42) — push subscribers into
 * the configured search engine.
 *
 * Not a cron job: reindexing is an operator action taken after switching
 * engines or changing the document shape, and adding a name here would not
 * be one of the 17 canonical §0.6 job names (D-40). Ongoing freshness comes
 * from the model observer, not from a schedule.
 */
class SearchReindexCommand extends Command
{
    protected $signature = 'emp:search-reindex {--customer= : Only this customer id} {--fresh : Drop the index first} {--sync : Run now instead of queueing}';

    protected $description = 'Index subscribers into the configured search engine (Meilisearch/Typesense).';

    public function handle(SearchIndexRegistry $registry, SubscriberSearchIndexer $indexer): int
    {
        $engine = $registry->active();

        if (! $engine->available()) {
            $this->warn(__('ops.search.no_engine', ['driver' => $engine->key()]));

            return self::SUCCESS;
        }

        $customerId = $this->option('customer') !== null ? (int) $this->option('customer') : null;
        $job = new ReindexSubscribersJob($customerId, (bool) $this->option('fresh'));

        if ($this->option('sync')) {
            $count = $job->handle($indexer);

            $this->info(__('ops.search.reindexed', [
                'count' => (string) $count,
                'index' => $indexer->indexName(),
            ]));

            return self::SUCCESS;
        }

        dispatch($job);

        $this->info(__('ops.search.queued', ['index' => $indexer->indexName()]));

        return self::SUCCESS;
    }
}
