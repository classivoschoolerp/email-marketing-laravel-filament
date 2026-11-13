<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Promo code discount type (§3.26.4 F-3.26.4-001). Percent values are
 * stored in basis points (1050 = 10.5%); Fixed values in minor units of
 * the platform default currency.
 */
enum PromoCodeType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function label(): string
    {
        return __('billing.promos.type.'.$this->value);
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
