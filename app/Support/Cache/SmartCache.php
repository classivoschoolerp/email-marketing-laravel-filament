<?php

declare(strict_types=1);

namespace App\Support\Cache;

use App\Services\Settings\SettingsReader;
use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * §0.1 F-0.1-016 — smart caching layer for UI/page performance (WP-24).
 *
 * A thin, honest wrapper over Laravel's cache for expensive dashboard/report
 * queries:
 *
 *  - **Buckets** (`dashboard`, `reports`, `lookups`) carry their own default
 *    TTL from config/emp-ops.php; the Ops settings group can override the
 *    global TTL and switch the whole layer off.
 *  - **Tags when the driver supports them** (redis/memcached/array) so a
 *    bucket can be invalidated in one call; on file/database drivers the key
 *    is simply prefixed and {@see forgetBucket()} reports that it cannot
 *    flush by tag rather than pretending it did.
 *  - **Never fails a request**: a cache-store error falls through to the
 *    callback.
 */
final class SmartCache
{
    public const BUCKET_DASHBOARD = 'dashboard';

    public const BUCKET_REPORTS = 'reports';

    public const BUCKET_LOOKUPS = 'lookups';

    /** @var list<string> */
    public const BUCKETS = [self::BUCKET_DASHBOARD, self::BUCKET_REPORTS, self::BUCKET_LOOKUPS];

    public static function enabled(): bool
    {
        if (! (bool) config('emp-ops.cache.enabled', true)) {
            return false;
        }

        try {
            return SettingsReader::ops()->smartCacheEnabled();
        } catch (Throwable) {
            return true;
        }
    }

    public static function taggable(): bool
    {
        try {
            return Cache::store()->getStore() instanceof TaggableStore;
        } catch (Throwable) {
            return false;
        }
    }

    public static function ttl(string $bucket): int
    {
        $configured = (int) config('emp-ops.cache.ttl.'.$bucket, 0);

        try {
            $override = SettingsReader::ops()->smartCacheTtlSeconds();
        } catch (Throwable) {
            $override = 0;
        }

        return $override > 0 ? $override : $configured;
    }

    public static function key(string $bucket, string $key): string
    {
        return (string) config('emp-ops.cache.prefix', 'emp:smart:').$bucket.':'.$key;
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public static function remember(string $bucket, string $key, Closure $callback, ?int $ttl = null): mixed
    {
        $ttl ??= self::ttl($bucket);

        if (! self::enabled() || $ttl <= 0) {
            return $callback();
        }

        try {
            return self::repository($bucket)->remember(self::key($bucket, $key), $ttl, $callback);
        } catch (Throwable) {
            return $callback();
        }
    }

    public static function forget(string $bucket, string $key): void
    {
        try {
            self::repository($bucket)->forget(self::key($bucket, $key));
        } catch (Throwable) {
            // Nothing to do — a stale entry expires on its own TTL.
        }
    }

    /**
     * Flush a whole bucket. Returns false (and flushes nothing) on a
     * non-taggable driver — the caller shows the honest note.
     */
    public static function forgetBucket(string $bucket): bool
    {
        if (! self::taggable()) {
            return false;
        }

        try {
            Cache::tags([self::tag($bucket)])->flush();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function tag(string $bucket): string
    {
        return (string) config('emp-ops.cache.prefix', 'emp:smart:').$bucket;
    }

    private static function repository(string $bucket): \Illuminate\Contracts\Cache\Repository
    {
        return self::taggable() ? Cache::tags([self::tag($bucket)]) : Cache::store();
    }
}
