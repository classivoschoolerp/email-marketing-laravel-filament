<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('twofa', function (SettingsBlueprint $blueprint): void {
            // F-3.25.6-001
            $blueprint->add('enabled', true);

            // F-3.25.6-002
            $blueprint->add('force_admins', false);
            $blueprint->add('force_customers', false);

            // F-3.25.6-003 — defaults mirror the WP-02 config('emp.twofa') stub
            $blueprint->add('issuer', null);
            $blueprint->add('code_validity_window', 1);
            $blueprint->add('recovery_code_count', 10);
        });
    }
};
