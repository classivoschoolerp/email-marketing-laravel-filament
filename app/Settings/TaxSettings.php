<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * §3.26.6 VAT / reverse-charge settings (F-3.26.6-003, WP-46).
 *
 * A NEW group rather than fields on MonetizationSettings, which is frozen.
 * Everything defaults to OFF or empty: the EU reverse-charge regime must
 * never switch itself on because an upgrade ran — it changes how much tax
 * is charged, and a wrong seller country would zero-rate invoices that
 * should carry VAT.
 *
 * The seller country lives here rather than in the §3.25.1 company block
 * because it is a TAX fact about the selling entity, and this group is
 * where the rest of the tax decision is configured.
 */
class TaxSettings extends Settings
{
    /**
     * F-3.26.6-003 — the selling entity's ISO 3166-1 alpha-2 country.
     * Null = unknown, and an unknown seller country disables the whole
     * reverse-charge branch (the regime is defined relative to the seller).
     */
    public ?string $seller_country;

    /** The seller's own VAT identification number, printed on invoices. */
    public ?string $seller_vat_number;

    /** F-3.26.6-003 — apply intra-EU reverse charge at all. */
    public bool $reverse_charge_enabled;

    /**
     * Zero-rate supplies to buyers outside the EU as out of scope. Separate
     * from reverse charge: it is a different legal basis and a different
     * invoice note.
     */
    public bool $zero_rate_non_eu;

    /** Query VIES before honouring a customer's VAT number. */
    public bool $vies_enabled;

    /** REST base URL; overridable for a proxy or a test double. */
    public string $vies_base_url;

    /** HTTP timeout in seconds for one VIES call. */
    public int $vies_timeout_seconds;

    /** Days a CONFIRMED VIES answer stays cached (failures cache minutes). */
    public int $vies_cache_days;

    public static function group(): string
    {
        return 'tax';
    }
}
