<?php

declare(strict_types=1);

namespace App\Services\Exports\Writers;

use League\Csv\Writer;

/**
 * CSV implementation of the WP-26 RowWriter — the existing league/csv
 * behaviour, byte-for-byte (fputcsv quoting, UTF-8 passthrough for CJK and
 * emoji), just behind the interface.
 */
final class CsvRowWriter implements RowWriter
{
    private Writer $writer;

    public function __construct(string $absolutePath)
    {
        $this->writer = Writer::createFromPath($absolutePath, 'w+');
    }

    /** CSV is single-sheet; the name is meaningless here. */
    public function sheet(string $name): void {}

    public function writeRow(array $row): void
    {
        $this->writer->insertOne($row);
    }

    public function close(): void {}
}
