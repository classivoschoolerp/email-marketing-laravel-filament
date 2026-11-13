<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant analytics integration credentials (spec §4.2 F-4.2-012, WP-40).
 *
 * GA4 is a per-property integration, so the measurement ID and API secret
 * belong to the customer, not to the install. `ga4_api_secret` uses the
 * `encrypted` cast: it is a credential and must never sit in plaintext in a
 * database dump.
 *
 * @property int $customer_id
 * @property bool $ga4_enabled
 * @property string|null $ga4_measurement_id
 * @property string|null $ga4_api_secret
 */
class CustomerAnalyticsSetting extends Model
{
    use BelongsToCustomer;

    /** A GA4 measurement ID always looks like G-XXXXXXXXXX. */
    public const MEASUREMENT_ID_PATTERN = '/^G-[A-Z0-9]{4,20}$/i';

    protected $guarded = ['id'];

    protected $attributes = [
        'ga4_enabled' => false,
        'ga4_track_opens' => true,
        'ga4_track_clicks' => true,
    ];

    protected function casts(): array
    {
        return [
            'ga4_enabled' => 'boolean',
            'ga4_api_secret' => 'encrypted',
            'ga4_track_opens' => 'boolean',
            'ga4_track_clicks' => 'boolean',
        ];
    }

    /**
     * GA4 is only live when the tenant enabled it AND both credentials are
     * present — a half-configured integration must be inert, not a source of
     * rejected HTTP calls on every open.
     */
    public function ga4Configured(): bool
    {
        return $this->ga4_enabled
            && is_string($this->ga4_measurement_id) && trim($this->ga4_measurement_id) !== ''
            && is_string($this->ga4_api_secret) && trim($this->ga4_api_secret) !== '';
    }

    /** The row for a tenant, created on demand with the safe defaults. */
    public static function forCustomer(int $customerId): self
    {
        /** @var self $row */
        $row = self::withoutTenancy()->firstOrCreate(['customer_id' => $customerId]);

        return $row;
    }
}
