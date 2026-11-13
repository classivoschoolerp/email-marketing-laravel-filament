<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('common', function (SettingsBlueprint $blueprint): void {
            // F-3.25.1-001
            $blueprint->add('site_name', 'Email Marketing Platform');
            $blueprint->add('site_description', null);
            $blueprint->add('site_tagline', null);
            $blueprint->add('site_keywords', null);

            // F-3.25.1-002
            $blueprint->add('logo_path', null);
            $blueprint->add('favicon_path', null);

            // F-3.25.1-003
            $blueprint->add('company_name', null);
            $blueprint->add('company_address', null);
            $blueprint->add('company_phone', null);
            $blueprint->add('company_email', null);
            $blueprint->add('company_vat', null);

            // F-3.25.1-004 / F-3.25.1-005
            $blueprint->add('default_timezone', 'UTC');
            $blueprint->add('date_format', 'Y-m-d');
            $blueprint->add('time_format', 'H:i');
            $blueprint->add('first_day_of_week', 1);
            $blueprint->add('default_language', 'en');

            // F-3.25.1-006
            $blueprint->add('clean_urls', true);

            // F-3.25.1-007 / F-3.25.1-008
            $blueprint->add('site_offline', false);
            $blueprint->add('site_offline_message', 'The site is currently offline for maintenance. Please check back soon.');
            $blueprint->add('api_offline', false);

            // F-3.25.1-009
            $blueprint->add('grid_page_size', 25);

            // F-3.25.1-010
            $blueprint->add('auto_update', false);

            // F-3.25.1-011
            $blueprint->add('maintenance_allowed_ips', []);

            // F-3.25.1-012
            $blueprint->add('recaptcha_site_key', null);
            $blueprint->addEncrypted('recaptcha_secret_key', null);

            // F-3.25.1-013
            $blueprint->add('admin_notification_emails', []);
        });
    }
};
