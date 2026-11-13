<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * §3.26.5 automatic exchange-rate updates (F-3.26.5-003, WP-46).
 *
 * A NEW group; MonetizationSettings is frozen. Defaults to the `manual`
 * provider, i.e. OFF: an upgrade must never start rewriting the exchange
 * rates an operator typed in, because those rates decide what customers see
 * quoted and what the revenue dashboard reports.
 */
class ExchangeRateSettings extends Settings
{
    /** ExchangeRateRegistry key; `manual` = never fetch anything. */
    public string $provider;

    /** Access key for providers that need one (encrypted at rest). */
    public ?string $api_key;

    /** Run the scheduled update at all. */
    public bool $auto_update_enabled;

    /**
     * Refuse a rate that moves more than this many BASIS POINTS in one run
     * (2000 bp = 20%). Real currencies move a few tenths of a percent a
     * day; a jump of this size means a bad feed, a wrong base or a decimal
     * error, and applying it would silently reprice the whole catalogue.
     */
    public int $max_change_bp;

    /** Hour of day (0-23, app timezone) the scheduled update runs. */
    public int $update_hour;

    /** Email the operator when a rate is refused for exceeding the limit. */
    public bool $alert_on_refusal;

    public static function group(): string
    {
        return 'exchange_rates';
    }

    /** @return list<string> */
    public static function encrypted(): array
    {
        return ['api_key'];
    }
}
