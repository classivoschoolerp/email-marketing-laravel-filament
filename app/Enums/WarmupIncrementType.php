<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a warmup plan's daily increment is expressed (spec §2.9.3 F-2.9.3-001
 * "daily increment (fixed or %)").
 *
 * Both types work under both strategies, and day 2 is identical either way —
 * only what happens after day 2 differs (see WarmupScheduleBuilder):
 *
 *  - Fixed    → linear step = increment; exponential factor = (start + increment) / start
 *  - Percent  → linear step = start · increment%; exponential factor = 1 + increment%
 */
enum WarmupIncrementType: string
{
    case Fixed = 'fixed';
    case Percent = 'percent';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => __('warmup.increment_type.fixed'),
            self::Percent => __('warmup.increment_type.percent'),
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
