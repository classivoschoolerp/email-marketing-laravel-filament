<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Inbox rendering preview settings (spec §2.3.5 F-2.3.5-006, WP-46).
 *
 * Ships with `provider = null` and no credentials on purpose: an upgrade must
 * never start uploading a customer's campaign HTML to a third-party rendering
 * farm because a migration ran.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('inbox_preview', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('provider', 'null');

            $blueprint->add('litmus_subdomain', null);
            $blueprint->addEncrypted('litmus_api_key', null);
            $blueprint->add('litmus_test_set', null);
            $blueprint->add('litmus_base_url', null);

            $blueprint->add('email_on_acid_api_user', null);
            $blueprint->addEncrypted('email_on_acid_api_key', null);
            $blueprint->add('email_on_acid_base_url', null);
            $blueprint->add('email_on_acid_clients', null);
        });
    }

    public function down(): void
    {
        $this->migrator->deleteGroup('inbox_preview');
    }
};
