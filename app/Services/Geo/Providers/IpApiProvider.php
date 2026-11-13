<?php

declare(strict_types=1);

namespace App\Services\Geo\Providers;

use App\Services\Geo\GeoLocation;
use App\Services\Geo\IpLocationProvider;
use App\Services\Settings\SettingsReader;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * ip-api.com provider (spec §3.23 F-3.23-001, D-10).
 *
 * Free tier: `http://ip-api.com/json/{ip}` — HTTP only, 45 req/min, no key.
 * Pro tier: `https://pro.ip-api.com/json/{ip}?key=...` — used automatically
 * when a key is stored in the Location settings.
 *
 * The `fields` mask keeps the response small and stable; `status=fail`
 * responses (reserved ranges, quota) are surfaced as null, never invented.
 */
final class IpApiProvider implements IpLocationProvider
{
    public const KEY = 'ip-api';

    private const FIELDS = 'status,message,country,countryCode,region,regionName,city,timezone,lat,lon';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('settings.location.providers.ip-api');
    }

    /** No credential is required on the free tier. */
    public function configured(): bool
    {
        return true;
    }

    public function locate(string $ip): ?GeoLocation
    {
        $key = SettingsReader::location()->ipapiKey();

        $url = $key !== null && trim($key) !== ''
            ? 'https://pro.ip-api.com/json/'.rawurlencode($ip)
            : 'http://ip-api.com/json/'.rawurlencode($ip);

        $query = ['fields' => self::FIELDS];

        if ($key !== null && trim($key) !== '') {
            $query['key'] = $key;
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('emp-ops.geo.http_timeout', 5))
                ->get($url, $query);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                __('settings.location.errors.lookup_failed', ['error' => $exception->getMessage()]),
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(__('settings.location.errors.lookup_failed', [
                'error' => 'HTTP '.$response->status(),
            ]));
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        if (($payload['status'] ?? null) !== 'success') {
            return null;
        }

        return new GeoLocation(
            ip: $ip,
            countryCode: $this->str($payload, 'countryCode'),
            countryName: $this->str($payload, 'country'),
            regionCode: $this->str($payload, 'region'),
            regionName: $this->str($payload, 'regionName'),
            city: $this->str($payload, 'city'),
            timezone: $this->str($payload, 'timezone'),
            latitude: isset($payload['lat']) && is_numeric($payload['lat']) ? (float) $payload['lat'] : null,
            longitude: isset($payload['lon']) && is_numeric($payload['lon']) ? (float) $payload['lon'] : null,
            provider: self::KEY,
        );
    }

    /** @param array<string, mixed> $payload */
    private function str(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
