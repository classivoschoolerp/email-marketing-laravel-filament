<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\ImportJob;
use Illuminate\Support\Facades\Storage;

/**
 * Appends failed rows to the import's downloadable error CSV
 * (F-2.2.7-012), stored next to the source file on the private disk at
 * customers/{uid}/imports/{import_uid}-errors.csv. Append-based so the
 * report survives time-budget requeues without buffering rows in memory.
 */
class ErrorReportWriter
{
    /** @param list<string> $errors */
    public function append(ImportJob $job, int $line, string $email, array $errors): void
    {
        $disk = Storage::disk('local');
        $path = $job->error_file_path ?? $job->storageDirectory().'/'.$job->uid.'-errors.csv';

        if (! $disk->exists($path)) {
            $disk->put($path, $this->line([
                __('imports.error_csv.line'),
                __('imports.error_csv.email'),
                __('imports.error_csv.errors'),
            ]));
        }

        $disk->append($path, $this->line([(string) $line, $email, implode(' | ', $errors)]));

        if ($job->error_file_path !== $path) {
            $job->error_file_path = $path;
            $job->save();
        }
    }

    /** @param list<string> $cells */
    private function line(array $cells): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $cells, ',', '"', '\\');
        rewind($handle);
        $line = (string) stream_get_contents($handle);
        fclose($handle);

        return rtrim($line, "\n");
    }
}
