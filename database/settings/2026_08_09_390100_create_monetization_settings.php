<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('monetization', function (SettingsBlueprint $blueprint): void {
            // F-3.25.14-001
            $blueprint->add('enabled', true);

            // F-3.25.14-002 — seeded from the superseded config defaults so
            // an existing install keeps its exact invoice numbering.
            $blueprint->add('default_currency', (string) config('emp-billing.default_currency', 'USD'));
            $blueprint->add('tax_mode', (string) config('emp-billing.tax_mode', 'exclusive'));
            $blueprint->add('invoice_prefix', (string) config('emp-billing.invoice.prefix', 'INV-'));
            $blueprint->add('invoice_number_padding', (int) config('emp-billing.invoice.pad', 6));

            // F-3.25.14-003
            $blueprint->add('trial_days', 0);
            $blueprint->add('trial_plan_id', null);

            // F-3.25.14-004
            $blueprint->add('require_plan', false);
        });
    }
};
