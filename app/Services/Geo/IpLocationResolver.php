<?php

declare(strict_types=1);

namespace App\Services\Geo;

use App\Services\Geo\Providers\NullProvider;
use App\Services\Settings\SettingsReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single entry point every caller uses for IP → location (spec §3.23,
 * D-10). Responsibilities:
 *
 *  - resolve the provider selected in the Location settings through the
 *    {@see GeoProviderRegistry};
 *  - **null-provider fast path**: when no provider is configured, `locate()`
 *    returns null without touching cache, settings-heavy code or the network,
 *    so the tracking hot path (§1.7) pays essentially nothing;
 *  - cache lookups for the configured TTL (hosted providers are rate-limited);
 *  - never throw at capture time — a failed lookup is logged and recorded as
 *    null, because losing an open/click event to a GeoIP outage is worse than
 *    losing the geo columns.
 */
class IpLocationResolver
{
    private const CACHE_PREFIX = 'geo:ip:';

    public function __construct(private readonly GeoProviderRegistry $registry) {}

    /** True when a real (non-null) provider is selected AND usable. */
    public function enabled(): bool
    {
        try {
            $key = SettingsReader::location()->provider();
        } catch (Throwable) {
            return false; // Settings not migrated yet (installer / first boot).
        }

        if ($key === NullProvider::KEY || ! $this->registry->has($key)) {
            return false;
        }

        try {
            return $this->registry->provider($key)->configured();
        } catch (Throwable) {
            return false;
        }
    }

    public function providerKey(): string
    {
        try {
            return SettingsReader::location()->provider();
        } catch (Throwable) {
            return NullProvider::KEY;
        }
    }

    /**
     * Capture-path lookup: never throws, honours the cache, returns null when
     * geo is off or the address is not routable.
     */
    public function locate(?string $ip): ?GeoLocation
    {
        if ($ip === null || $ip === '' || ! $this->enabled()) {
            return null;
        }

        if (! $this->routable($ip)) {
            return null;
        }

        $ttl = SettingsReader::location()->cacheTtlMinutes();
        $key = self::CACHE_PREFIX.$this->providerKey().':'.$ip;

        if ($ttl > 0) {
            /** @var array<string, mixed>|null $cached */
            $cached = Cache::get($key);

            if (is_array($cached)) {
                return $this->hydrate($cached);
            }
        }

        $location = $this->attempt($ip);

        if ($ttl > 0 && $location !== null) {
            Cache::put($key, $location->toArray(), now()->addMinutes($ttl));
        }

        return $location;
    }

    /**
     * Admin test-lookup path (F-3.23-003): bypasses the cache and lets the
     * provider exception surface so the operator sees the real reason.
     */
    public function lookup(string $ip): ?GeoLocation
    {
        $key = $this->providerKey();

        if ($key === NullProvider::KEY || ! $this->registry->has($key)) {
            return null;
        }

        return $this->registry->provider($key)->locate($ip);
    }

    private function attempt(string $ip): ?GeoLocation
    {
        try {
            return $this->lookup($ip);
        } catch (Throwable $exception) {
            Log::warning('GeoIP lookup failed', [
                'ip' => $ip,
                'provider' => $this->providerKey(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /** Private/reserved ranges never resolve — skip the round trip. */
    public function routable(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /** @param array<string, mixed> $data */
    private function hydrate(array $data): GeoLocation
    {
        return new GeoLocation(
            ip: (string) ($data['ip'] ?? ''),
            countryCode: $data['country_code'] ?? null,
            countryName: $data['country_name'] ?? null,
            regionCode: $data['region_code'] ?? null,
            regionName: $data['region_name'] ?? null,
            city: $data['city'] ?? null,
            timezone: $data['timezone'] ?? null,
            latitude: $data['latitude'] ?? null,
            longitude: $data['longitude'] ?? null,
            provider: $data['provider'] ?? null,
        );
    }
}
