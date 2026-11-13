<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Google Postmaster Tools settings (spec §2.9.9 F-2.9.9-005, WP-46).
 *
 * Ships DISABLED with no credentials: an upgrade must never start shipping a
 * customer's domain names to a third party because a migration ran (same rule
 * as the SSO group, D-108 territory).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('postmaster', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('enabled', false);
            $blueprint->addEncrypted('service_account_json', null);
            $blueprint->add('delegated_user', null);
            $blueprint->add('domains', null);
            $blueprint->add('backfill_days', 3);
        });
    }

    public function down(): void
    {
        $this->migrator->deleteGroup('postmaster');
    }
};
