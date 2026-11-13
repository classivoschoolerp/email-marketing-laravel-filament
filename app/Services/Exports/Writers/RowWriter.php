<?php

declare(strict_types=1);

namespace App\Services\Exports\Writers;

/**
 * Streaming tabular writer (WP-26, F-2.3.8-018). One row at a time, never
 * an in-memory row set — subscriber exports run to millions of rows
 * (§4.6 F-4.6-005 chunked exports), so both implementations must keep a
 * single open file handle and flush as they go.
 *
 * Sheets: CSV has exactly one (sheet() is a no-op after the first call);
 * XLSX supports many, which is how the "full report" export becomes one
 * workbook with a tab per report section instead of a ZIP of CSVs.
 */
interface RowWriter
{
    /** Start (or switch to) a named sheet. */
    public function sheet(string $name): void;

    /** @param list<int|float|string|null> $row */
    public function writeRow(array $row): void;

    public function close(): void;
}
