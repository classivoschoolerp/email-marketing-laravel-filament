<?php

declare(strict_types=1);

namespace App\Services\Geo;

use App\Settings\LocationSettings;
use App\Support\Files;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * MaxMind database upload + auto-update (spec §3.23 F-3.23-002, D-10).
 *
 * Downloads `{edition_id}.tar.gz` from MaxMind's permalink endpoint using the
 * operator's license key, extracts the single `.mmdb` member and stores it on
 * the `local` disk, then points the Location settings at the new file.
 *
 * The tar.gz is unpacked with a 60-line reader instead of PharData because
 * `phar` is commonly disabled on cPanel/Plesk (`disable_functions`/
 * `suhosin`), which would make auto-update fail exactly where it matters.
 */
class MaxMindDatabaseUpdater
{
    /** @return string the relative disk path of the installed database */
    public function update(): string
    {
        $settings = app(LocationSettings::class);

        $key = (string) ($settings->maxmind_license_key ?? '');
        $edition = $settings->maxmind_edition;

        if (trim($key) === '') {
            throw new RuntimeException(__('settings.location.errors.maxmind_license_missing'));
        }

        try {
            $response = Http::timeout((int) config('emp-ops.geo.http_timeout', 5) * 12)
                ->get((string) config('emp-ops.geo.maxmind_download_url'), [
                    'edition_id' => $edition,
                    'license_key' => $key,
                    'suffix' => 'tar.gz',
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                __('settings.location.errors.maxmind_download_failed', ['error' => $exception->getMessage()]),
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(__('settings.location.errors.maxmind_download_failed', [
                'error' => 'HTTP '.$response->status(),
            ]));
        }

        $database = $this->extractMmdb($response->body());

        if ($database === null) {
            throw new RuntimeException(__('settings.location.errors.maxmind_archive_invalid'));
        }

        $path = $this->store($edition, $database);

        $settings->maxmind_database_path = $path;
        $settings->maxmind_updated_at = Carbon::now()->toIso8601String();
        $settings->maxmind_database_uploaded_at = Carbon::now()->toIso8601String();
        $settings->save();

        return $path;
    }

    /** Persist raw .mmdb bytes (upload path) and return the relative path. */
    public function store(string $edition, string $contents): string
    {
        $directory = Files::safeRelative((string) config('emp-ops.geo.database_directory', 'geoip'));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $edition) ?: 'GeoLite2-City';
        $path = $directory.'/'.$name.'.mmdb';

        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    /**
     * Minimal gzip+tar reader: returns the first `.mmdb` member's bytes.
     */
    public function extractMmdb(string $archive): ?string
    {
        $tar = @gzdecode($archive);

        if ($tar === false) {
            // Some mirrors serve the raw tar (or the raw .mmdb) unchanged.
            $tar = $archive;
        }

        if (str_starts_with($tar, "\xAB\xCD\xEF")) {
            return $tar; // Already a bare .mmdb — nothing to unpack.
        }

        $offset = 0;
        $length = strlen($tar);

        while ($offset + 512 <= $length) {
            $header = substr($tar, $offset, 512);
            $offset += 512;

            $name = rtrim(substr($header, 0, 100), "\0");

            if ($name === '') {
                break; // Two zero blocks end the archive.
            }

            $sizeField = trim(substr($header, 124, 12), "\0 ");
            $size = $sizeField === '' ? 0 : (int) octdec($sizeField);
            $type = substr($header, 156, 1);

            if (($type === '0' || $type === "\0") && str_ends_with(strtolower($name), '.mmdb')) {
                return substr($tar, $offset, $size);
            }

            // Payloads are padded to the next 512-byte boundary.
            $offset += (int) (ceil($size / 512) * 512);
        }

        return null;
    }
}
