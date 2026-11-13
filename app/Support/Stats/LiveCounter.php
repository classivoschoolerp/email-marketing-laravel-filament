<?php

declare(strict_types=1);

namespace App\Support\Stats;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Cache-backed live counters flushed to DB periodically (spec §4.6). Producers call
 * increment(); the maintenance flush job drains via drain() and persists. Keys are
 * tracked in a registry set so drain() knows what exists without SCAN support.
 */
final class LiveCounter
{
    private const REGISTRY = 'live-counters:registry';

    public function __construct(private readonly Cache $cache)
    {
    }

    public function increment(string $counter, int $amount = 1): void
    {
        $key = 'live-counters:'.$counter;

        $this->cache->add($key, 0, 86400);
        $this->cache->increment($key, $amount);

        $registry = $this->cache->get(self::REGISTRY, []);
        if (! in_array($counter, $registry, true)) {
            $registry[] = $counter;
            $this->cache->put(self::REGISTRY, $registry, 86400);
        }
    }

    public function value(string $counter): int
    {
        return (int) $this->cache->get('live-counters:'.$counter, 0);
    }

    /**
     * Atomically read-and-reset all counters. Returns [counter => value] for values > 0.
     * @return array<string, int>
     */
    public function drain(): array
    {
        $out = [];

        foreach ($this->cache->get(self::REGISTRY, []) as $counter) {
            $key = 'live-counters:'.$counter;
            $value = (int) $this->cache->pull($key, 0);
            if ($value > 0) {
                $out[$counter] = $value;
            }
        }

        $this->cache->forget(self::REGISTRY);

        return $out;
    }
}
