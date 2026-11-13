<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Warmup ramp curve (spec §2.9.3 F-2.9.3-007).
 *
 *  - Linear: the daily step is CONSTANT — allowance(d) = start + step·(d−1).
 *  - Exponential: the daily step grows with the volume — the schedule
 *    compounds, allowance(d) = start · factor^(d−1).
 *
 * How `step` / `factor` are derived from the plan's increment lives in
 * {@see WarmupIncrementType}.
 */
enum WarmupStrategy: string
{
    case Linear = 'linear';
    case Exponential = 'exponential';

    public function label(): string
    {
        return match ($this) {
            self::Linear => __('warmup.strategy.linear'),
            self::Exponential => __('warmup.strategy.exponential'),
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
