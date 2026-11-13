<?php

declare(strict_types=1);

namespace App\Services\Imports\Readers;

use App\Models\ImportJob;
use App\Services\Imports\Esp\EspImporterRegistry;
use App\Services\Imports\ImportFailedException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Rows from another ESP's contact API (spec §2.2.7 F-2.2.7-006 — Mailchimp,
 * Brevo, Constant Contact, Klaviyo).
 *
 * There is deliberately NO second importer here. prepare() pages the vendor
 * API through the {@see EspImporterRegistry} adapter, materialises the
 * contacts as a normal CSV on the tenant's private disk, and then hands the
 * job to {@see CsvFileReader} — so mapping, auto-created fields, duplicate
 * handling, suppression, the chunked progress/ETA loop and the error CSV are
 * literally the P1 code paths, unchanged.
 *
 * Because the file is written before the first row is imported, a
 * time-budget requeue resumes from the stored file instead of re-paging the
 * vendor (prepare() only downloads when `file_path` is still null).
 *
 * Column set, stated plainly: the CSV header is the union of the field keys
 * seen on the FIRST page. Vendors return a stable attribute set per account,
 * and buffering every page to compute a global header would defeat the
 * streaming write — so a key that appears for the first time on a later page
 * is dropped rather than silently shifting every column.
 */
class EspReader implements ImportReader
{
    public function __construct(
        private readonly EspImporterRegistry $registry,
        private readonly CsvFileReader $csv,
    ) {}

    public function prepare(ImportJob $job): void
    {
        if ($job->file_path === null) {
            $this->download($job);
        }

        // From here on it is an ordinary CSV import.
        $this->csv->prepare($job);
    }

    public function totalRows(ImportJob $job): int
    {
        return $this->csv->totalRows($job);
    }

    public function rows(ImportJob $job, int $offset): \Generator
    {
        yield from $this->csv->rows($job, $offset);
    }

    /** Page the vendor API into a CSV on the private disk. */
    private function download(ImportJob $job): void
    {
        $options = (array) $job->options;
        $esp = (array) ($options['esp'] ?? []);
        $driver = (string) ($esp['driver'] ?? '');

        if (! $this->registry->has($driver)) {
            throw new ImportFailedException(__('imports.errors.esp_unknown', ['driver' => $driver]));
        }

        $importer = $this->registry->for($driver);
        $credentials = $this->credentials($esp);

        $handle = fopen('php://temp/maxmemory:2097152', 'w+b');

        if ($handle === false) {
            throw new ImportFailedException(__('imports.errors.unexpected'));
        }

        $maxPages = max(1, (int) config('emp-imports.esp.max_pages', 5000));
        $path = $job->storageDirectory().'/'.$job->uid.'.csv';
        $headers = null;
        $cursor = null;
        $pages = 0;
        $written = 0;

        try {
            do {
                $page = $importer->fetch($credentials, $cursor);
                $pages++;

                foreach ($page->rows as $row) {
                    if ($headers === null) {
                        $headers = array_keys($row['fields']);
                        fputcsv($handle, array_merge(['email'], $headers), ',', '"', '\\');
                    }

                    $cells = [$row['email']];

                    foreach ($headers as $header) {
                        $cells[] = (string) ($row['fields'][$header] ?? '');
                    }

                    fputcsv($handle, $cells, ',', '"', '\\');
                    $written++;
                }

                $cursor = $page->cursor;
            } while ($cursor !== null && $pages < $maxPages);

            if ($headers === null) {
                // No contacts at all: still write a header so the CSV reader
                // finds an email column and the run completes with 0 rows.
                fputcsv($handle, ['email'], ',', '"', '\\');
            }

            rewind($handle);

            Storage::disk('local')->writeStream($path, $handle);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $options['has_header'] = true;
        $options['delimiter'] = ',';
        $options['enclosure'] = '"';
        $options['esp'] = array_merge($esp, ['fetched_rows' => $written, 'fetched_pages' => $pages]);

        $job->options = $options;
        $job->file_path = $path;
        $job->save();
    }

    /**
     * Decrypt the per-run vendor credentials stored by the import form.
     *
     * @param  array<string, mixed>  $esp
     * @return array<string, string>
     */
    private function credentials(array $esp): array
    {
        $encrypted = (string) ($esp['credentials_encrypted'] ?? '');

        if ($encrypted === '') {
            throw new ImportFailedException(__('imports.errors.esp_credentials_missing'));
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ImportFailedException(__('imports.errors.esp_credentials_missing'));
        }

        return array_map(strval(...), (array) $decoded);
    }
}
