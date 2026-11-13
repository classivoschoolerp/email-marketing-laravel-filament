<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * SSO settings (spec §6.7 P2 rows, WP-38).
 *
 * Everything defaults OFF. A one-time login token logs somebody in with no
 * password and no second factor; social login hands account creation to a
 * third party. Neither may become live because an upgrade ran.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('sso', function (SettingsBlueprint $blueprint): void {
            // F-6.7-001
            $blueprint->add('login_token_enabled', false);
            $blueprint->add('login_token_ttl_seconds', 60);

            // F-6.7-003
            $blueprint->add('google_enabled', false);
            $blueprint->addEncrypted('google_client_secret', null);
            $blueprint->add('google_client_id', null);

            $blueprint->add('microsoft_enabled', false);
            $blueprint->addEncrypted('microsoft_client_secret', null);
            $blueprint->add('microsoft_client_id', null);
            $blueprint->add('microsoft_tenant', 'common');

            $blueprint->add('auto_create_accounts', false);
        });
    }

    public function down(): void
    {
        $this->migrator->deleteGroup('sso');
    }
};
