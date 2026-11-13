<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('api', function (SettingsBlueprint $blueprint): void {
            // F-3.25.7-001
            $blueprint->add('enabled', true);

            // F-3.25.7-002
            $blueprint->add('require_signature', false);

            // F-3.25.7-003
            $blueprint->add('ip_allowlist', []);
            $blueprint->add('ip_blocklist', []);

            // F-3.25.7-004 — 0 = unlimited (D-23)
            $blueprint->add('rate_limit_per_minute', 60);
            $blueprint->add('rate_limit_per_hour', 1000);

            // F-3.25.7-005
            $blueprint->add('max_records_per_page', 100);

            // F-3.25.7-006
            $blueprint->add('cors_origins', []);

            // F-3.25.7-007 — 0 retention = keep forever (D-23)
            $blueprint->add('request_logging_enabled', false);
            $blueprint->add('request_log_retention_days', 30);
        });
    }
};
