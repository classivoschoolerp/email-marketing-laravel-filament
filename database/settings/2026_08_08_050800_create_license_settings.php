<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('license', function (SettingsBlueprint $blueprint): void {
            // F-3.25.12-001
            $blueprint->addEncrypted('license_key', null);

            // F-3.25.12-002
            $blueprint->add('status', 'unknown');
            $blueprint->add('type', null);
            $blueprint->add('expires_at', null);
            $blueprint->add('allowed_domains', []);

            // F-3.25.12-003
            $blueprint->add('renewal_url', null);
            $blueprint->add('last_checked_at', null);
        });
    }
};
