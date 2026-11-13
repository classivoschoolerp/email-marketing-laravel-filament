<?php

declare(strict_types=1);

namespace App\Jobs\Subscribers;

use App\Models\Subscriber;
use App\Services\Subscribers\SubscriberSearchIndexer;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bulk (re)index of subscribers into the configured search engine (spec
 * §0.1 F-0.1-008, WP-42).
 *
 * Queued and chunked per docs/ARCHITECTURE.md rule 5 — a full reindex reads every
 * subscriber row on the install and must never run inside a web request.
 * Tenant-agnostic on purpose: it runs `withoutTenancy()` because it is an
 * operator/infrastructure action, and the documents it writes carry the
 * `customer_id` that fences every later read.
 */
class ReindexSubscribersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ?int $customerId = null,
        public readonly bool $flushFirst = false,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(SubscriberSearchIndexer $indexer): int
    {
        if (! $indexer->enabled()) {
            return 0;
        }

        if ($this->flushFirst) {
            $indexer->flush();
        }

        $indexed = 0;
        $chunk = max(1, (int) config('emp-search.chunk', 500));

        Subscriber::withoutTenancy()
            ->when($this->customerId !== null, fn ($q) => $q->where('customer_id', $this->customerId))
            ->with('fieldValues')
            ->orderBy('id')
            ->chunkById($chunk, function ($subscribers) use ($indexer, &$indexed): void {
                $indexed += $indexer->upsert($subscribers);
            });

        return $indexed;
    }
}
