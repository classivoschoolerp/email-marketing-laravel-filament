<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\Subscribers\ReindexSubscribersJob;
use App\Models\Subscriber;
use App\Services\Subscribers\SubscriberSearchIndexer;

/**
 * Keeps the search index fresh (spec §0.1 F-0.1-008, WP-42).
 *
 * Always registered; the work is gated by the DatabaseSearchIndex null
 * object, so on the default driver every method here returns without
 * touching anything. Single-row writes go straight through: a subscriber
 * save is one small HTTP call and the engines batch internally. Bulk paths
 * (imports, list tools) run {@see ReindexSubscribersJob}
 * instead of firing one call per row.
 */
class SubscriberSearchObserver
{
    public function __construct(private readonly SubscriberSearchIndexer $indexer) {}

    public function saved(Subscriber $subscriber): void
    {
        $this->indexer->upsert([$subscriber]);
    }

    public function deleted(Subscriber $subscriber): void
    {
        $this->indexer->forget([(int) $subscriber->id]);
    }

    public function restored(Subscriber $subscriber): void
    {
        $this->indexer->upsert([$subscriber]);
    }
}
