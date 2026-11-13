<?php

declare(strict_types=1);

namespace App\Services\Exports\Writers;

use App\Enums\ExportFormat;

/**
 * Picks the WP-26 RowWriter for an export format (F-2.3.8-018). Kept tiny
 * and separate so a future format (ODS — openspout ships a writer for it)
 * is one case, not a refactor.
 *
 * WP-36 (§6.1 F-6.1-014): a plugin-registered factory in RowWriterRegistry
 * wins over the built-in match, so an export target can be added — or a
 * built-in one replaced — without a core change. With no plugin registered
 * the registry is empty and behaviour is byte-for-byte what it was.
 */
final class RowWriterFactory
{
    public static function make(ExportFormat $format, string $absolutePath): RowWriter
    {
        $plugin = app(RowWriterRegistry::class)->make($format, $absolutePath);

        if ($plugin !== null) {
            return $plugin;
        }

        return match ($format) {
            ExportFormat::Xlsx => new XlsxRowWriter($absolutePath),
            ExportFormat::Csv => new CsvRowWriter($absolutePath),
        };
    }
}
