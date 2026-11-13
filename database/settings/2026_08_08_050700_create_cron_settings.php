<?php

declare(strict_types=1);

use App\Settings\CronSettings;
use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('cron', function (SettingsBlueprint $blueprint): void {
            // F-3.25.11-001
            $blueprint->add('emails_per_batch', 500);
            $blueprint->add('subscribers_per_batch', 1000);
            $blueprint->add('parallel_processes', 1);

            // F-3.25.11-002 — all known §0.6 jobs enabled by default
            $blueprint->add('enabled_jobs', CronSettings::KNOWN_JOBS);

            // F-3.25.11-004
            $blueprint->add('delivery_concurrency', 2);
            $blueprint->add('bounce_concurrency', 1);
        });
    }
};
