<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('location', function (SettingsBlueprint $blueprint): void {
            // F-3.23-001
            $blueprint->add('provider', 'null');

            // F-3.23-002
            $blueprint->add('maxmind_database_path', null);
            $blueprint->add('maxmind_database_uploaded_at', null);
            $blueprint->add('maxmind_edition', 'GeoLite2-City');
            $blueprint->addEncrypted('maxmind_license_key', null);
            $blueprint->add('maxmind_auto_update', false);
            $blueprint->add('maxmind_updated_at', null);

            $blueprint->addEncrypted('ipinfo_token', null);
            $blueprint->addEncrypted('ipapi_key', null);

            $blueprint->add('cache_ttl_minutes', 1440);

            $blueprint->add('enrich_tracking', true);
            $blueprint->add('enrich_login_logs', true);
        });
    }
};
