<?php

declare(strict_types=1);

namespace App\Services\Geo;

use App\Models\Country;
use App\Models\Zone;
use App\Support\Geo\Countries;
use App\Support\Geo\Zones;
use InvalidArgumentException;
use JsonException;

/**
 * Countries/zones dataset importer (spec §3.23 F-3.23-004/005, WP-24).
 *
 * Two sources:
 *  - the built-in seed: the WP-02 ISO 3166-1 list (App\Support\Geo\Countries)
 *    and the authored ISO 3166-2 subdivisions (App\Support\Geo\Zones);
 *  - operator uploads: CSV (header row) or JSON (array of objects).
 *
 * Imports are idempotent upserts keyed on `countries.code` and
 * `(country_id, zones.code)` so re-running never duplicates rows and never
 * destroys operator edits to unrelated columns.
 */
class GeoDataImporter
{
    public const FORMAT_CSV = 'csv';

    public const FORMAT_JSON = 'json';

    /** @return int rows written */
    public function importBuiltInCountries(): int
    {
        $rows = [];

        foreach (Countries::fallback() as $code => $name) {
            $rows[] = ['code' => $code, 'name' => $name];
        }

        return $this->importCountries($rows);
    }

    /** @return int rows written */
    public function importBuiltInZones(): int
    {
        $rows = [];

        foreach (Zones::seed() as $countryCode => $zones) {
            foreach ($zones as [$code, $name, $type]) {
                $rows[] = [
                    'country_code' => $countryCode,
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                ];
            }
        }

        return $this->importZones($rows);
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return int rows written
     */
    public function importCountries(iterable $rows): int
    {
        $written = 0;

        foreach ($rows as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));

            if (strlen($code) !== 2 || $name === '') {
                continue;
            }

            Country::query()->updateOrCreate(
                ['code' => $code],
                array_filter([
                    'name' => $name,
                    'code3' => $this->nullableUpper($row['code3'] ?? null, 3),
                    'numeric_code' => $this->nullableString($row['numeric_code'] ?? null, 3),
                    'phone_prefix' => $this->nullableString($row['phone_prefix'] ?? null, 8),
                    'active' => array_key_exists('active', $row) ? (bool) $row['active'] : true,
                ], static fn (mixed $value): bool => $value !== null),
            );

            $written++;
        }

        return $written;
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return int rows written
     */
    public function importZones(iterable $rows): int
    {
        $written = 0;
        $countryIds = [];

        foreach ($rows as $row) {
            $countryCode = strtoupper(trim((string) ($row['country_code'] ?? $row['country'] ?? '')));
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));

            if ($countryCode === '' || $code === '' || $name === '') {
                continue;
            }

            if (! array_key_exists($countryCode, $countryIds)) {
                $countryIds[$countryCode] = Country::query()->where('code', $countryCode)->value('id');
            }

            $countryId = $countryIds[$countryCode];

            if ($countryId === null) {
                continue; // Import countries first — never invent a parent row.
            }

            Zone::query()->updateOrCreate(
                ['country_id' => $countryId, 'code' => $code],
                array_filter([
                    'name' => $name,
                    'type' => $this->nullableString($row['type'] ?? null, 32),
                    'active' => array_key_exists('active', $row) ? (bool) $row['active'] : true,
                ], static fn (mixed $value): bool => $value !== null),
            );

            $written++;
        }

        return $written;
    }

    /**
     * Parse an uploaded countries/zones file.
     *
     * @return list<array<string, mixed>>
     */
    public function parse(string $contents, string $format): array
    {
        return match ($format) {
            self::FORMAT_JSON => $this->parseJson($contents),
            self::FORMAT_CSV => $this->parseCsv($contents),
            default => throw new InvalidArgumentException("Unsupported import format [{$format}]."),
        };
    }

    public function formatFor(string $filename): string
    {
        return str_ends_with(strtolower($filename), '.json') ? self::FORMAT_JSON : self::FORMAT_CSV;
    }

    /** @return list<array<string, mixed>> */
    private function parseJson(string $contents): array
    {
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                __('settings.location.errors.import_invalid_json', ['error' => $exception->getMessage()]),
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(__('settings.location.errors.import_invalid_json', ['error' => 'not a list']));
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    private function parseCsv(string $contents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($contents)) ?: [];

        if ($lines === [] || $lines[0] === '') {
            return [];
        }

        $header = array_map(
            static fn (string $column): string => strtolower(trim($column, " \t\"'")),
            str_getcsv(array_shift($lines)),
        );

        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            $row = [];

            foreach ($header as $index => $column) {
                if ($column === '') {
                    continue;
                }

                $row[$column] = $values[$index] ?? null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function nullableUpper(mixed $value, int $max): ?string
    {
        $string = $this->nullableString($value, $max);

        return $string === null ? null : strtoupper($string);
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : mb_substr($string, 0, $max);
    }
}
