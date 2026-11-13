<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * §3.26.6 VAT / reverse-charge settings (F-3.26.6-003, WP-46).
 *
 * Every switch defaults OFF and the seller country defaults to NULL. An
 * upgrade must never start zero-rating invoices on its own: reverse charge
 * with an unset or wrong seller country would drop VAT from supplies that
 * legally carry it, and that is the operator's liability, not a UI glitch.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('tax', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('seller_country', null);
            $blueprint->add('seller_vat_number', null);

            $blueprint->add('reverse_charge_enabled', false);
            $blueprint->add('zero_rate_non_eu', false);

            $blueprint->add('vies_enabled', false);
            $blueprint->add('vies_base_url', 'https://ec.europa.eu/taxation_customs/vies/rest-api' /* ViesValidator::DEFAULT_BASE_URL */);
            $blueprint->add('vies_timeout_seconds', 10);
            $blueprint->add('vies_cache_days', 7);
        });
    }

    public function down(): void
    {
        $this->migrator->deleteGroup('tax');
    }
};
