<?php

declare(strict_types=1);

namespace App\Services\Exports\Writers;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * XLSX implementation of the WP-26 RowWriter, built on openspout
 * (openspout/openspout ^4.32 — already a project dependency).
 *
 * openspout writes SpreadsheetML in streaming mode: rows go straight into
 * the sheet's XML part, so memory stays flat regardless of row count and a
 * multi-million-row subscriber export behaves exactly like the CSV path.
 *
 * Sheet names: Excel forbids []:*?/\ and caps names at 31 characters —
 * sanitised here so a report tab name can be passed in verbatim.
 */
final class XlsxRowWriter implements RowWriter
{
    private Writer $writer;

    private bool $firstSheetUsed = false;

    public function __construct(string $absolutePath)
    {
        $this->writer = new Writer;
        $this->writer->openToFile($absolutePath);
    }

    public function sheet(string $name): void
    {
        $sheet = $this->firstSheetUsed
            ? $this->writer->addNewSheetAndMakeItCurrent()
            : $this->writer->getCurrentSheet();

        $this->firstSheetUsed = true;

        $sheet->setName($this->sanitize($name));
    }

    public function writeRow(array $row): void
    {
        // Cells are written as-is; openspout types them (numeric strings stay
        // strings, so ids and phone numbers do not lose leading zeroes).
        $this->writer->addRow(Row::fromValues(array_map(
            static fn ($value) => $value === null ? '' : $value,
            $row,
        )));
    }

    public function close(): void
    {
        $this->writer->close();
    }

    private function sanitize(string $name): string
    {
        $name = preg_replace('/[\\\\\\/\\*\\?\\[\\]:]/', '-', $name) ?? $name;
        $name = trim($name) !== '' ? trim($name) : 'Sheet';

        return mb_substr($name, 0, 31);
    }
}
