<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * Autoresponder delay unit (spec §2.3.2 F-2.3.2-015).
 */
enum AutoresponderDelayUnit: string
{
    case Minutes = 'minutes';
    case Hours = 'hours';
    case Days = 'days';
    case Weeks = 'weeks';
    case Months = 'months';
    case Years = 'years';

    public function label(): string
    {
        return __('campaigns.autoresponder.delay_unit.'.$this->value);
    }

    public function addTo(Carbon $moment, int $value): Carbon
    {
        return match ($this) {
            self::Minutes => $moment->addMinutes($value),
            self::Hours => $moment->addHours($value),
            self::Days => $moment->addDays($value),
            self::Weeks => $moment->addWeeks($value),
            self::Months => $moment->addMonths($value),
            self::Years => $moment->addYears($value),
        };
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
