<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * §3.25.16 privacy / GDPR settings (WP-22) + the §4.5 compliance switches.
 * Every toggle ships OFF so an existing installation behaves exactly as it
 * did before the group existed; retention values default to NULL = inherit
 * config/emp-sending.php.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('privacy', function (SettingsBlueprint $blueprint): void {
            // F-3.25.16-001
            $blueprint->add('cookie_consent_enabled', false);
            $blueprint->add('cookie_consent_text', null);
            $blueprint->add('cookie_policy_url', null);

            // F-3.25.16-002 — NULL = inherit config('emp-sending.retention.*').
            $blueprint->add('retention_delivery_log_days', null);
            $blueprint->add('retention_open_days', null);
            $blueprint->add('retention_click_days', null);
            $blueprint->add('retention_export_days', null);

            // F-3.25.16-003
            $blueprint->add('erasure_workflow_enabled', false);

            // F-3.25.16-004 (+ §4.5 sub-processor list)
            $blueprint->add('dpa_text', null);
            $blueprint->add('sub_processors_text', null);

            // F-3.25.16-005
            $blueprint->add('anonymize_ips', false);

            // §4.5 GDPR portability + CAN-SPAM enforcement.
            $blueprint->add('data_export_enabled', true);
            $blueprint->add('canspam_enforce_address', false);
        });
    }
};
