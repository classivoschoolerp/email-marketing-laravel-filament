<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Price-plan billing models (§3.26.1 F-3.26.1-001 intervals + F-3.26.1-011
 * AC models). PerCredit is the model of credit top-up packs.
 *
 * PerList and PerSend are METERED (WP-27C): the plan's price is
 * `base_price + max(0, units - metered_included) * unit price`, where units
 * are the tenant's list count (per-list, measured at renewal/checkout) or
 * the sends delivered inside the billed window (per-send, accrued and billed
 * in arrears). Both bill on a monthly cadence — see periodMonths() — and
 * `metered_max` caps the units (0 = unlimited, D-23). PlanMetering owns the
 * arithmetic; every value is integer minor units.
 */
enum BillingModel: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
    case OneTime = 'one-time';
    case Lifetime = 'lifetime';
    case PerCredit = 'per-credit';
    case PerList = 'per-list';
    case PerSend = 'per-send';

    public function label(): string
    {
        return __('billing.plans.model.'.$this->value);
    }

    /**
     * Models that produce a renewable, time-boxed subscription. The metered
     * pair belongs here too (F-3.26.1-011): a per-list or per-send plan bills
     * every month for whatever the tenant used.
     */
    public function isRecurringInterval(): bool
    {
        return in_array($this, [
            self::Monthly,
            self::Quarterly,
            self::Yearly,
            self::PerList,
            self::PerSend,
        ], true);
    }

    /** Months a subscription period lasts; null = never expires. */
    public function periodMonths(): ?int
    {
        return match ($this) {
            self::Monthly, self::PerList, self::PerSend => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
            default => null,
        };
    }

    /** F-3.26.1-011: usage-metered models (price scales with units). */
    public function isMetered(): bool
    {
        return in_array($this, [self::PerList, self::PerSend], true);
    }

    /**
     * Metered units are counted at the moment of billing (per-list = how many
     * lists exist now) or accrued over the billed window (per-send = sends
     * delivered since the last completed renewal, billed in arrears).
     */
    public function meterIsUsageWindow(): bool
    {
        return $this === self::PerSend;
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
