<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('social', function (SettingsBlueprint $blueprint): void {
            // F-3.25.13-001
            $blueprint->add('facebook_url', null);
            $blueprint->add('twitter_url', null);
            $blueprint->add('instagram_url', null);
            $blueprint->add('linkedin_url', null);
            $blueprint->add('youtube_url', null);
            $blueprint->add('tiktok_url', null);
        });
    }
};
