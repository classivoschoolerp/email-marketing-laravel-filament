<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\SlowQuery;
use App\Models\WorkerNode;
use App\Services\Reports\QueueMonitorService;
use App\Support\Ops\SlowQueryRecorder;
use App\Support\System\Cpu;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * §6.6 F-6.6-003 performance monitoring panel (WP-24): slow queries, queue
 * depth, worker count and cache hit rate.
 *
 * Honest reporting rules:
 *  - queue depth reuses the WP-17 {@see QueueMonitorService}, which already
 *    reports "unavailable" on non-database drivers rather than inventing
 *    numbers (D-04);
 *  - worker count comes from the F-6.6-008 registry — it is the number of
 *    processes that have checked in, not a guess from `ps`;
 *  - cache hit rate is only reported for drivers that actually expose the
 *    counters (Redis INFO / Memcached stats). Everything else returns
 *    `supported: false` with the driver name, never a fabricated percentage.
 */
class PerformanceMonitor
{
    public function __construct(private readonly QueueMonitorService $queues) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'slow_queries' => $this->slowQuerySummary(),
            'queues' => [
                'supported' => $this->queues->supported(),
                'driver' => (string) config('queue.default'),
                'total_pending' => $this->queues->supported() ? $this->queues->totalPending() : null,
                'failed' => $this->queues->failedCount(),
                'per_queue' => $this->queues->queues(),
            ],
            'workers' => $this->workerSummary(),
            'cache' => $this->cacheHitRate(),
        ];
    }

    /** @return array{enabled: bool, threshold_ms: int, total: int, last_24h: int, slowest_ms: ?int} */
    public function slowQuerySummary(): array
    {
        $enabled = SlowQueryRecorder::enabled();

        return [
            'enabled' => $enabled,
            'threshold_ms' => SlowQueryRecorder::thresholdMs(),
            'total' => (int) SlowQuery::query()->count(),
            'last_24h' => (int) SlowQuery::query()->where('created_at', '>=', Carbon::now()->subDay())->count(),
            'slowest_ms' => SlowQuery::query()->max('time_ms') === null ? null : (int) SlowQuery::query()->max('time_ms'),
        ];
    }

    /**
     * Worst offenders grouped by normalised SQL shape.
     *
     * @return list<array{fingerprint: string, sql: string, hits: int, max_ms: int, avg_ms: int}>
     */
    public function topSlowQueries(int $limit = 10): array
    {
        return SlowQuery::query()
            ->selectRaw('fingerprint, max(time_ms) as max_ms, avg(time_ms) as avg_ms, count(*) as hits, max(id) as last_id')
            ->groupBy('fingerprint')
            ->orderByDesc('max_ms')
            ->limit($limit)
            ->get()
            ->map(function (SlowQuery $row): array {
                $sample = SlowQuery::query()->find($row->getAttribute('last_id'));

                return [
                    'fingerprint' => (string) $row->fingerprint,
                    'sql' => (string) ($sample?->sql ?? ''),
                    'hits' => (int) $row->getAttribute('hits'),
                    'max_ms' => (int) $row->getAttribute('max_ms'),
                    'avg_ms' => (int) round((float) $row->getAttribute('avg_ms')),
                ];
            })
            ->all();
    }

    /**
     * @return array{alive: int, stale: int, suggested: array{sending: int, general: int, scheduler: int, total: int}, cores: int, cores_source: string, note: string}
     */
    public function workerSummary(): array
    {
        return [
            'alive' => (int) WorkerNode::query()->alive()->count(),
            'stale' => (int) WorkerNode::query()->stale()->count(),
            'cores' => Cpu::cores(),
            'cores_source' => Cpu::source(),
            'suggested' => Cpu::suggestedWorkers(),
            // The registry only knows about processes that ran a job or a
            // heartbeat — say so instead of implying a full process list.
            'note' => __('ops.performance.worker_note'),
        ];
    }

    /**
     * @return array{supported: bool, driver: string, hits: ?int, misses: ?int, ratio: ?float, note: ?string}
     */
    public function cacheHitRate(): array
    {
        $driver = (string) config('cache.default');

        try {
            $store = Cache::store()->getStore();

            if ($store instanceof \Illuminate\Cache\RedisStore) {
                return $this->redisHitRate($store, $driver);
            }

            if ($store instanceof \Illuminate\Cache\MemcachedStore) {
                return $this->memcachedHitRate($store, $driver);
            }
        } catch (Throwable $exception) {
            return $this->unsupportedCache($driver, $exception->getMessage());
        }

        return $this->unsupportedCache($driver, null);
    }

    /** Slow-query retention pruning, called by `logs:prune`-style maintenance. */
    public function pruneSlowQueries(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return (int) SlowQuery::query()
            ->where('created_at', '<', Carbon::now()->subDays($days))
            ->delete();
    }

    /** Driver-aware "is the DB reachable" probe used by /health. */
    public function databaseReachable(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{supported: bool, driver: string, hits: ?int, misses: ?int, ratio: ?float, note: ?string} */
    private function redisHitRate(\Illuminate\Cache\RedisStore $store, string $driver): array
    {
        /** @var array<string, mixed>|string $info */
        $info = $store->connection()->command('info', ['stats']);

        if (is_string($info)) {
            $info = $this->parseRedisInfo($info);
        }

        $hits = isset($info['keyspace_hits']) ? (int) $info['keyspace_hits'] : null;
        $misses = isset($info['keyspace_misses']) ? (int) $info['keyspace_misses'] : null;

        if ($hits === null || $misses === null) {
            return $this->unsupportedCache($driver, null);
        }

        $total = $hits + $misses;

        return [
            'supported' => true,
            'driver' => $driver,
            'hits' => $hits,
            'misses' => $misses,
            'ratio' => $total > 0 ? round($hits / $total * 100, 1) : null,
            'note' => null,
        ];
    }

    /** @return array{supported: bool, driver: string, hits: ?int, misses: ?int, ratio: ?float, note: ?string} */
    private function memcachedHitRate(\Illuminate\Cache\MemcachedStore $store, string $driver): array
    {
        $stats = $store->getMemcached()->getStats();
        $hits = 0;
        $misses = 0;

        foreach ((array) $stats as $server) {
            $hits += (int) ($server['get_hits'] ?? 0);
            $misses += (int) ($server['get_misses'] ?? 0);
        }

        $total = $hits + $misses;

        return [
            'supported' => true,
            'driver' => $driver,
            'hits' => $hits,
            'misses' => $misses,
            'ratio' => $total > 0 ? round($hits / $total * 100, 1) : null,
            'note' => null,
        ];
    }

    /** @return array{supported: bool, driver: string, hits: null, misses: null, ratio: null, note: string} */
    private function unsupportedCache(string $driver, ?string $error): array
    {
        return [
            'supported' => false,
            'driver' => $driver,
            'hits' => null,
            'misses' => null,
            'ratio' => null,
            'note' => $error ?? __('ops.performance.cache_unsupported', ['driver' => $driver]),
        ];
    }

    /** @return array<string, string> */
    private function parseRedisInfo(string $raw): array
    {
        $values = [];

        foreach (explode("\n", $raw) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', trim($line), 2);
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
