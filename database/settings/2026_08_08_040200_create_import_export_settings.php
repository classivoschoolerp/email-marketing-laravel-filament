<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('import_export', function (SettingsBlueprint $blueprint): void {
            // F-3.25.2-001
            $blueprint->add('max_import_file_size_mb', 50);
            $blueprint->add('allowed_import_extensions', ['csv', 'txt', 'xlsx']);

            // F-3.25.2-002
            $blueprint->add('import_batch_size', 500);
            $blueprint->add('import_memory_limit_mb', 256);
            $blueprint->add('import_max_execution_seconds', 300);

            // F-3.25.2-003
            $blueprint->add('export_batch_size', 1000);
            $blueprint->add('export_max_rows_per_file', 100000);

            // F-3.25.2-004
            $blueprint->add('import_from_url_enabled', false);
            $blueprint->add('import_from_database_enabled', false);

            // F-3.25.2-005
            $blueprint->add('export_retention_days', 7);
        });
    }
};
