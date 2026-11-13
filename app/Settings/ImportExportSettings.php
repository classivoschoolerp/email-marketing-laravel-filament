<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Import/Export settings (spec §3.25.2).
 */
class ImportExportSettings extends Settings
{
    // Import file constraints (F-3.25.2-001)
    public int $max_import_file_size_mb;

    /** @var list<string> */
    public array $allowed_import_extensions;

    // Import runtime limits (F-3.25.2-002)
    public int $import_batch_size;

    public int $import_memory_limit_mb;

    /** 0 = unlimited. */
    public int $import_max_execution_seconds;

    // Export limits (F-3.25.2-003)
    public int $export_batch_size;

    public int $export_max_rows_per_file;

    // Import sources (F-3.25.2-004)
    public bool $import_from_url_enabled;

    public bool $import_from_database_enabled;

    // Export retention (F-3.25.2-005)
    public int $export_retention_days;

    public static function group(): string
    {
        return 'import_export';
    }
}
