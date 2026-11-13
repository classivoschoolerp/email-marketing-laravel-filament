<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\ImportExportSettings;

/**
 * Typed accessors for the `import_export` settings group (spec §3.25.2).
 */
final readonly class ImportExportSettingsReader
{
    public function __construct(private ImportExportSettings $settings) {}

    public function maxImportFileSizeMb(): int
    {
        return $this->settings->max_import_file_size_mb;
    }

    /**
     * @return list<string>
     */
    public function allowedImportExtensions(): array
    {
        return $this->settings->allowed_import_extensions;
    }

    public function importBatchSize(): int
    {
        return $this->settings->import_batch_size;
    }

    public function importMemoryLimitMb(): int
    {
        return $this->settings->import_memory_limit_mb;
    }

    /**
     * 0 = unlimited.
     */
    public function importMaxExecutionSeconds(): int
    {
        return $this->settings->import_max_execution_seconds;
    }

    public function exportBatchSize(): int
    {
        return $this->settings->export_batch_size;
    }

    public function exportMaxRowsPerFile(): int
    {
        return $this->settings->export_max_rows_per_file;
    }

    public function importFromUrlEnabled(): bool
    {
        return $this->settings->import_from_url_enabled;
    }

    public function importFromDatabaseEnabled(): bool
    {
        return $this->settings->import_from_database_enabled;
    }

    public function exportRetentionDays(): int
    {
        return $this->settings->export_retention_days;
    }
}
