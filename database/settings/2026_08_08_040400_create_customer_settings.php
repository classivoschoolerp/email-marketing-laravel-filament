<?php

declare(strict_types=1);

use App\Settings\CustomerSettings;
use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('customer', function (SettingsBlueprint $blueprint): void {
            // F-3.25.4-001
            $blueprint->add('registration_enabled', true);
            $blueprint->add('registration_requires_approval', false);
            $blueprint->add('registration_requires_email_confirmation', true);

            // F-3.25.4-002
            $blueprint->add('default_customer_group_id', null);

            // F-3.25.4-003
            $blueprint->add('allowed_sending_server_types', CustomerSettings::SENDING_SERVER_TYPES);

            // F-3.25.4-004 — 0 = unlimited
            $blueprint->add('default_max_lists', 0);
            $blueprint->add('default_max_campaigns', 0);
            $blueprint->add('default_max_subscribers', 0);
            $blueprint->add('default_sending_quota', 0);
            $blueprint->add('default_sending_quota_period', 'monthly');
            $blueprint->add('default_cdn_enabled', false);
            $blueprint->add('default_cdn_url', null);
            $blueprint->add('default_api_enabled', true);
            $blueprint->add('default_api_rate_limit_per_minute', 60);

            // F-3.25.4-005
            $blueprint->add('force_system_servers_only', false);

            // F-3.25.4-006
            $blueprint->add('welcome_email_enabled', true);

            // F-3.25.4-007
            $blueprint->add('self_serve_deletion_enabled', true);

            // F-3.25.4-008
            $blueprint->add('terms_acceptance_required', true);

            // F-3.25.4-009
            $blueprint->add('minimum_password_strength', CustomerSettings::PASSWORD_STRENGTH_MEDIUM);

            // F-3.25.4-010
            $blueprint->add('inactive_auto_suspend_days', 0);
        });
    }
};
