<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Automation lifecycle (spec §2.4.1): draft → active ⇄ paused. Only active
 * automations enroll subscribers and advance runs; paused blocks both.
 */
enum AutomationStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';

    public function label(): string
    {
        return __('automations.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Paused => 'warning',
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
