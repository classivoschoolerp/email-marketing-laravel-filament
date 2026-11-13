<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('dkim', function (SettingsBlueprint $blueprint): void {
            // F-3.25.10-001
            $blueprint->add('enabled', false);
            $blueprint->add('selector', 'default');
            $blueprint->addEncrypted('private_key', null);
            $blueprint->add('public_key', null);

            // F-3.25.10-002
            $blueprint->add('force_global_signing', false);
        });
    }
};
