<?php

declare(strict_types=1);

namespace App\Services\Geo\Providers;

use App\Services\Geo\GeoLocation;
use App\Services\Geo\IpLocationProvider;
use App\Services\Settings\SettingsReader;
use App\Support\Geo\Countries;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * ipinfo.io provider (spec §3.23 F-3.23-001, D-10).
 *
 * `https://ipinfo.io/{ip}/json?token=...`. A token is required (the tokenless
 * tier is rate-limited to the point of being unusable for tracking), so
 * `configured()` is false until one is stored — the resolver then falls back
 * to recording nulls rather than hammering a 429.
 *
 * The API returns `region` as the full subdivision name and `country` as the
 * ISO-2 code; the country NAME is resolved from the platform countries table
 * (§3.23 F-3.23-004) so both columns are populated.
 */
final class IpInfoProvider implements IpLocationProvider
{
    public const KEY = 'ipinfo';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('settings.location.providers.ipinfo');
    }

    public function configured(): bool
    {
        $token = SettingsReader::location()->ipinfoToken();

        return $token !== null && trim($token) !== '';
    }

    public function locate(string $ip): ?GeoLocation
    {
        $token = SettingsReader::location()->ipinfoToken();

        if ($token === null || trim($token) === '') {
            throw new RuntimeException(__('settings.location.errors.ipinfo_token_missing'));
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('emp-ops.geo.http_timeout', 5))
                ->withToken($token)
                ->get('https://ipinfo.io/'.rawurlencode($ip).'/json');
        } catch (Throwable $exception) {
            throw new RuntimeException(
                __('settings.location.errors.lookup_failed', ['error' => $exception->getMessage()]),
                previous: $exception,
            );
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException(__('settings.location.errors.lookup_failed', [
                'error' => 'HTTP '.$response->status(),
            ]));
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        if (($payload['bogon'] ?? false) === true) {
            return null;
        }

        $countryCode = $this->str($payload, 'country');
        [$latitude, $longitude] = $this->coordinates($this->str($payload, 'loc'));

        return new GeoLocation(
            ip: $ip,
            countryCode: $countryCode,
            countryName: $countryCode === null ? null : (Countries::all()[$countryCode] ?? null),
            regionCode: null,
            regionName: $this->str($payload, 'region'),
            city: $this->str($payload, 'city'),
            timezone: $this->str($payload, 'timezone'),
            latitude: $latitude,
            longitude: $longitude,
            provider: self::KEY,
        );
    }

    /** @return array{0: ?float, 1: ?float} */
    private function coordinates(?string $loc): array
    {
        if ($loc === null || ! str_contains($loc, ',')) {
            return [null, null];
        }

        [$lat, $lon] = array_map('trim', explode(',', $loc, 2));

        return [
            is_numeric($lat) ? (float) $lat : null,
            is_numeric($lon) ? (float) $lon : null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function str(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
