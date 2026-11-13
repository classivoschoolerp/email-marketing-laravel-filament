<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\LogArchive;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Archival / cold storage for old logs (spec §4.6 F-4.6-008, WP-42).
 *
 * `logs:prune` used to enforce retention by deleting; with
 * `emp-archive.enabled` on it calls this service first, so every batch is on
 * a filesystem disk (S3/Glacier in production, `local` in dev) before it
 * leaves the database.
 *
 * FORMAT: gzipped NDJSON — one JSON object per row, one file per prune run
 * per table. Chosen over CSV because these tables carry nullable columns and
 * JSON payloads, and over a SQL dump because NDJSON restores into MySQL and
 * SQLite alike (D-03) and can be grepped without this application.
 *
 * ORDERING GUARANTEE: bytes first, delete second, and the delete is by the
 * exact primary keys that were written. A crash between the two can leave a
 * duplicate archive of rows that still exist; it can never delete a row that
 * was not archived. Restoring uses insertOrIgnore, so a duplicate archive is
 * harmless.
 */
class LogArchiver
{
    public function enabled(): bool
    {
        return (bool) config('emp-archive.enabled', false);
    }

    /** True when this table is on the archiving allow-list. */
    public function archives(string $table): bool
    {
        return in_array($table, (array) config('emp-archive.tables', []), true);
    }

    public function chunkSize(): int
    {
        return max(1, (int) config('emp-archive.chunk', 5000));
    }

    /**
     * Write one batch of rows to cold storage.
     *
     * @param  list<object>  $rows  raw stdClass rows straight from the query builder
     * @return LogArchive|null null when the batch is empty
     */
    public function archiveBatch(string $table, array $rows): ?LogArchive
    {
        if ($rows === []) {
            return null;
        }

        $disk = (string) config('emp-archive.disk', 'local');
        $prefix = trim((string) config('emp-archive.path', 'log-archives'), '/');

        $body = '';
        $start = null;
        $end = null;

        foreach ($rows as $row) {
            $data = (array) $row;
            $body .= json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

            $createdAt = $data['created_at'] ?? null;

            if (is_string($createdAt) && $createdAt !== '') {
                $start = $start === null || $createdAt < $start ? $createdAt : $start;
                $end = $end === null || $createdAt > $end ? $createdAt : $end;
            }
        }

        $compressed = gzencode($body, 6);

        if ($compressed === false) {
            throw new RuntimeException('Could not compress the log archive batch.');
        }

        $path = sprintf(
            '%s/%s/%s-%s.ndjson.gz',
            $prefix,
            $table,
            Carbon::now()->format('Ymd-His'),
            bin2hex(random_bytes(4)),
        );

        // A failed write must abort the batch — the caller only deletes after
        // this method returns a persisted archive row.
        if (Storage::disk($disk)->put($path, $compressed) === false) {
            throw new RuntimeException('Could not write the log archive to disk ['.$disk.'].');
        }

        return LogArchive::query()->create([
            'table_name' => $table,
            'disk' => $disk,
            'path' => $path,
            'rows_count' => count($rows),
            'bytes' => strlen($compressed),
            'period_start' => $start,
            'period_end' => $end,
        ]);
    }

    /**
     * Read an archive back into its table. Rows that are still present are
     * skipped (insertOrIgnore on the primary key), so restoring twice — or
     * restoring a duplicate archive — is a no-op rather than a constraint
     * violation.
     *
     * @return int rows inserted
     */
    public function restore(LogArchive $archive): int
    {
        $table = (string) $archive->table_name;

        if (! Schema::hasTable($table)) {
            throw new RuntimeException('Table ['.$table.'] no longer exists.');
        }

        if (! $archive->exists()) {
            throw new RuntimeException('Archive file ['.$archive->path.'] is missing from disk ['.$archive->disk.'].');
        }

        $raw = Storage::disk((string) $archive->disk)->get((string) $archive->path);
        $body = $raw === null ? false : gzdecode($raw);

        if ($body === false) {
            throw new RuntimeException('Archive file ['.$archive->path.'] could not be decompressed.');
        }

        $inserted = 0;
        $buffer = [];

        foreach (explode("\n", $body) as $line) {
            if (trim($line) === '') {
                continue;
            }

            /** @var array<string, mixed> $row */
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $buffer[] = $row;

            if (count($buffer) >= $this->chunkSize()) {
                $inserted += (int) DB::table($table)->insertOrIgnore($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $inserted += (int) DB::table($table)->insertOrIgnore($buffer);
        }

        $archive->forceFill(['restored_at' => Carbon::now()])->save();

        return $inserted;
    }
}
