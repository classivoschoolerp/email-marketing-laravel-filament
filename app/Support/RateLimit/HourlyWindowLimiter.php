<?php

declare(strict_types=1);

namespace App\Support\RateLimit;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * Fixed-window counters for per-delivery-server quotas (spec §0.4: "Rate limiting per
 * delivery server"). Driver-agnostic: uses atomic cache increments, so it is backed by
 * Redis in production and the database/array store in dev+tests (docs/DECISIONS.md D-04).
 */
final class HourlyWindowLimiter
{
    public function __construct(private readonly Cache $cache)
    {
    }

    public function hit(string $key, int $amount = 1): int
    {
        $cacheKey = $this->windowKey($key);

        // add() is atomic: first hit creates the counter. The key embeds the hour, so a
        // TTL of just over one hour guarantees expiry without racing the window edge.
        $this->cache->add($cacheKey, 0, 3700);

        return (int) $this->cache->increment($cacheKey, $amount);
    }

    public function used(string $key): int
    {
        return (int) $this->cache->get($this->windowKey($key), 0);
    }

    public function remaining(string $key, int $limit): int
    {
        return max(0, $limit - $this->used($key));
    }

    public function wouldExceed(string $key, int $limit, int $amount = 1): bool
    {
        return $limit > 0 && ($this->used($key) + $amount) > $limit;
    }

    public function secondsUntilReset(): int
    {
        // Carbon 3 returns float from diffInSeconds — cast keeps the contract.
        return (int) ceil(Carbon::now()->diffInSeconds(Carbon::now()->addHour()->startOfHour(), true));
    }

    private function windowKey(string $key): string
    {
        return 'hourly:'.$key.':'.Carbon::now()->format('YmdH');
    }
}
