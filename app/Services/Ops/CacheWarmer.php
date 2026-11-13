<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Support\Cache\SmartCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §0.1 F-0.1-016 cache warming (WP-24). Pre-computes the counts the admin
 * dashboard and Ops page read on every render, storing them in the
 * `dashboard` bucket of {@see SmartCache}.
 *
 * Every metric is guarded by a `Schema::hasTable()` check so warming works on
 * a partially migrated install (installer, fresh clone) instead of exploding.
 */
class CacheWarmer
{
    /** table => cache key */
    private const COUNTS = [
        'customers' => 'counts.customers',
        'lists' => 'counts.lists',
        'subscribers' => 'counts.subscribers',
        'campaigns' => 'counts.campaigns',
        'delivery_servers' => 'counts.delivery_servers',
    ];

    /** @return array<string, int> the values written */
    public function warm(): array
    {
        $warmed = [];

        foreach (self::COUNTS as $table => $key) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $value = (int) DB::table($table)->count();

            SmartCache::forget(SmartCache::BUCKET_DASHBOARD, $key);
            SmartCache::remember(
                SmartCache::BUCKET_DASHBOARD,
                $key,
                static fn (): int => $value,
            );

            $warmed[$key] = $value;
        }

        return $warmed;
    }

    /** @return bool false when the driver cannot flush by tag (honest note) */
    public function flush(): bool
    {
        $flushed = true;

        foreach (SmartCache::BUCKETS as $bucket) {
            $flushed = SmartCache::forgetBucket($bucket) && $flushed;
        }

        return $flushed;
    }

    /** Read a warmed count (falls back to a live query). */
    public function count(string $table): int
    {
        $key = self::COUNTS[$table] ?? ('counts.'.$table);

        return (int) SmartCache::remember(
            SmartCache::BUCKET_DASHBOARD,
            $key,
            static fn (): int => Schema::hasTable($table) ? (int) DB::table($table)->count() : 0,
        );
    }
}
