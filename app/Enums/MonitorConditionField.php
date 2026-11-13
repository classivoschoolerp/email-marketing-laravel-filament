<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Message part a monitor condition inspects (spec §2.9.6 F-2.9.6-002).
 */
enum MonitorConditionField: string
{
    case Subject = 'subject';
    case Body = 'body';
    case From = 'from';

    public function label(): string
    {
        return match ($this) {
            self::Subject => __('infra.monitors.field.subject'),
            self::Body => __('infra.monitors.field.body'),
            self::From => __('infra.monitors.field.from'),
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
