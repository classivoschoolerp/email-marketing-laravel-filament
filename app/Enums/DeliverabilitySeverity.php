<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Severity of a deliverability alert (spec §2.9.9 F-2.9.9-006).
 */
enum DeliverabilitySeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => __('deliverability.severity.info'),
            self::Warning => __('deliverability.severity.warning'),
            self::Critical => __('deliverability.severity.critical'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Warning => 'warning',
            self::Critical => 'danger',
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
