<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * §3.25.14 monetization / SaaS settings (WP-39).
 *
 * This group supersedes `config/emp-billing.php` for every key it names —
 * the config file stays as the pre-migration fallback (the installer runs
 * before the settings table exists) exactly like the `emp.twofa` stub does
 * for the §3.25.6 group (D-36). Readers must therefore never assume the
 * settings row exists; go through MonetizationSettingsReader, which
 * degrades to config on any settings failure.
 *
 * `enabled = false` turns SaaS billing off entirely: every customer-facing
 * billing surface (plans, checkout, orders, credits, payment methods) is
 * hidden and the renewal engine stops. It does NOT delete data.
 */
class MonetizationSettings extends Settings
{
    /** F-3.25.14-001 — master switch for SaaS billing. */
    public bool $enabled;

    /** F-3.25.14-002 — ISO 4217 code used when no currency row is default. */
    public string $default_currency;

    /** F-3.25.14-002 — 'exclusive' (added on top) | 'inclusive' (contained). */
    public string $tax_mode;

    /** F-3.25.14-002 — invoice/order number prefix. */
    public string $invoice_prefix;

    /** F-3.25.14-002 — zero-padding width of the sequential number. */
    public int $invoice_number_padding;

    /** F-3.25.14-003 — default trial length in days (0 = no trial, D-23). */
    public int $trial_days;

    /** F-3.25.14-003 — price_plans.id offered as the trial plan; null = none. */
    public ?int $trial_plan_id;

    /** F-3.25.14-004 — block the account until a plan/trial is active. */
    public bool $require_plan;

    public static function group(): string
    {
        return 'monetization';
    }
}
