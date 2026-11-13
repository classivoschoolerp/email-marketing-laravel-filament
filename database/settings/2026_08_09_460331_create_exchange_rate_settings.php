<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * §3.26.5 automatic exchange-rate updates (F-3.26.5-003, WP-46).
 *
 * The provider defaults to `manual` and auto-update defaults to OFF. Rates
 * decide what a customer is quoted in a foreign currency; an upgrade must
 * never start rewriting them on its own. The 2000 bp (20%) tolerance is the
 * refusal threshold, not a target — see ExchangeRateUpdater.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('exchange_rates', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('provider', 'manual' /* ManualProvider::KEY */);
            $blueprint->addEncrypted('api_key', null);
            $blueprint->add('auto_update_enabled', false);
            $blueprint->add('max_change_bp', 2_000);
            $blueprint->add('update_hour', 3);
            $blueprint->add('alert_on_refusal', true);
        });
    }

    public function down(): void
    {
        $this->migrator->deleteGroup('exchange_rates');
    }
};
