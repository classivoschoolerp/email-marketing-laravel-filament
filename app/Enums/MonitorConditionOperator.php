<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a monitor condition compares its value (spec §2.9.6 F-2.9.6-002 —
 * "contains" with regex support). Regex patterns are full PCRE including
 * delimiters and are validated at the form; an invalid pattern never matches.
 */
enum MonitorConditionOperator: string
{
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case Regex = 'regex';

    public function label(): string
    {
        return match ($this) {
            self::Contains => __('infra.monitors.operator.contains'),
            self::NotContains => __('infra.monitors.operator.not_contains'),
            self::Regex => __('infra.monitors.operator.regex'),
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
