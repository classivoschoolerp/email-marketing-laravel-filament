<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('cdn', function (SettingsBlueprint $blueprint): void {
            // F-3.25.9-001
            $blueprint->add('enabled', false);
            $blueprint->add('base_url', null);
            $blueprint->add('subdomain', null);
            $blueprint->add('use_for_assets', true);
            $blueprint->add('use_for_email_images', true);

            // F-3.25.9-002
            $blueprint->add('provider', 'custom');
            $blueprint->add('s3_bucket', null);
            $blueprint->add('s3_region', null);
            $blueprint->add('s3_access_key_id', null);
            $blueprint->addEncrypted('s3_secret_access_key', null);
            $blueprint->add('cloudfront_distribution_id', null);
            $blueprint->add('bunny_storage_zone', null);
            $blueprint->addEncrypted('bunny_api_key', null);
            $blueprint->add('bunny_pull_zone', null);
        });
    }
};
