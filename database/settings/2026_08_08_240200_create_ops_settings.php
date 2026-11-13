<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('ops', function (SettingsBlueprint $blueprint): void {
            // F-6.6-002
            $blueprint->add('error_notifications_enabled', true);
            $blueprint->add('error_notification_level', 'ERROR');

            // F-6.6-003
            $blueprint->add('slow_query_logging', false);
            $blueprint->add('slow_query_threshold_ms', 500);
            $blueprint->add('slow_query_retention_days', 7);

            // F-6.6-005
            $blueprint->add('disk_free_percent_warning', 15);
            $blueprint->add('memory_percent_warning', 85);
            $blueprint->add('database_size_mb_warning', 10240);
            $blueprint->add('threshold_email_enabled', false);
            $blueprint->add('threshold_email_to', null);

            // F-6.6-006
            $blueprint->add('backup_include_uploads', true);
            $blueprint->add('backup_retention_days', 7);
            $blueprint->add('backup_time', '01:00');

            // F-6.6-008
            $blueprint->add('worker_stale_seconds', 120);

            // F-0.1-016
            $blueprint->add('smart_cache_enabled', true);
            $blueprint->add('smart_cache_ttl_seconds', 300);

            // F-0.1-009
            $blueprint->add('object_storage_enabled', false);
            $blueprint->add('object_storage_disk', 's3');
        });
    }
};
