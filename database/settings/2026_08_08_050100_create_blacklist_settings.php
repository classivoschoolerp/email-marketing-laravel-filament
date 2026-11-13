<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('blacklist', function (SettingsBlueprint $blueprint): void {
            // F-3.25.5-001
            $blueprint->add('enabled', true);

            // F-3.25.5-002
            $blueprint->add('regex_rules', []);

            // F-3.25.5-003
            $blueprint->add('apply_at', 'both');

            // F-3.25.5-004
            $blueprint->add('shared_across_customers', true);
        });
    }
};
