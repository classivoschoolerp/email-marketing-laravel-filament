<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the currency symbol renders relative to the amount (§3.26.5).
 */
enum CurrencyPlacement: string
{
    case Before = 'before';
    case After = 'after';

    public function label(): string
    {
        return __('billing.currencies.placement.'.$this->value);
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
