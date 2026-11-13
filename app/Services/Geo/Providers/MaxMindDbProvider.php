<?php

declare(strict_types=1);

namespace App\Services\Geo\Providers;

use App\Services\Geo\GeoLocation;
use App\Services\Geo\IpLocationProvider;
use App\Services\Settings\SettingsReader;
use App\Support\Files;
use App\Support\Geo\MaxMindDbReader;
use RuntimeException;
use Throwable;

/**
 * MaxMind GeoLite2 / GeoIP2 local-database provider (spec §3.23 F-3.23-001/002,
 * D-10). Reads the .mmdb uploaded through the Location settings page with the
 * dependency-free {@see MaxMindDbReader}; no MaxMind account is needed to read
 * a database file that the operator already has.
 *
 * When no database has been uploaded the provider reports `configured() ===
 * false` and `locate()` throws — it never pretends to resolve.
 */
final class MaxMindDbProvider implements IpLocationProvider
{
    public const KEY = 'maxmind';

    private ?MaxMindDbReader $reader = null;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('settings.location.providers.maxmind');
    }

    public function databasePath(): ?string
    {
        $relative = SettingsReader::location()->maxmindDatabasePath();

        if ($relative === null || trim($relative) === '') {
            return null;
        }

        return Files::storagePath($relative);
    }

    public function configured(): bool
    {
        $path = $this->databasePath();

        return $path !== null && is_file($path) && is_readable($path);
    }

    public function locate(string $ip): ?GeoLocation
    {
        $record = $this->reader()->get($ip);

        if (! is_array($record)) {
            return null;
        }

        return new GeoLocation(
            ip: $ip,
            countryCode: $this->str($record, ['country', 'iso_code']) ?? $this->str($record, ['registered_country', 'iso_code']),
            countryName: $this->name($record, ['country', 'names']) ?? $this->name($record, ['registered_country', 'names']),
            regionCode: $this->subdivision($record, 'iso_code'),
            regionName: $this->subdivision($record, 'names'),
            city: $this->name($record, ['city', 'names']),
            timezone: $this->str($record, ['location', 'time_zone']),
            latitude: $this->float($record, ['location', 'latitude']),
            longitude: $this->float($record, ['location', 'longitude']),
            provider: self::KEY,
        );
    }

    /**
     * Metadata for the settings page (edition + build date).
     *
     * @return array{type: string, build_epoch: int}|null
     */
    public function databaseInfo(): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $reader = $this->reader();
        } catch (Throwable) {
            return null;
        }

        return [
            'type' => $reader->databaseType(),
            'build_epoch' => $reader->buildEpoch(),
        ];
    }

    private function reader(): MaxMindDbReader
    {
        $path = $this->databasePath();

        if ($path === null) {
            throw new RuntimeException(__('settings.location.errors.maxmind_missing'));
        }

        return $this->reader ??= new MaxMindDbReader($path);
    }

    /** @param array<string, mixed> $record */
    private function str(array $record, array $path): ?string
    {
        $value = $this->dig($record, $path);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $record */
    private function float(array $record, array $path): ?float
    {
        $value = $this->dig($record, $path);

        return is_float($value) || is_int($value) ? (float) $value : null;
    }

    /**
     * MaxMind names are a locale map; prefer the active app locale, then en.
     *
     * @param  array<string, mixed>  $record
     */
    private function name(array $record, array $path): ?string
    {
        $names = $this->dig($record, $path);

        if (! is_array($names)) {
            return null;
        }

        foreach ([app()->getLocale(), 'en'] as $locale) {
            if (is_string($names[$locale] ?? null) && $names[$locale] !== '') {
                return $names[$locale];
            }
        }

        $first = reset($names);

        return is_string($first) && $first !== '' ? $first : null;
    }

    /** @param array<string, mixed> $record */
    private function subdivision(array $record, string $field): ?string
    {
        $subdivisions = $record['subdivisions'] ?? null;

        if (! is_array($subdivisions) || $subdivisions === []) {
            return null;
        }

        $first = $subdivisions[0] ?? null;

        if (! is_array($first)) {
            return null;
        }

        if ($field === 'names') {
            return $this->name($first, ['names']);
        }

        return is_string($first[$field] ?? null) && $first[$field] !== '' ? $first[$field] : null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $path
     */
    private function dig(array $record, array $path): mixed
    {
        $value = $record;

        foreach ($path as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }
}
