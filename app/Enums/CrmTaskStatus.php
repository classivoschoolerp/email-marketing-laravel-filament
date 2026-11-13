<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * §2.13 F-2.13-004 task state. Only `Open` rows are ever reminded — a
 * completed or cancelled task must not email anybody.
 */
enum CrmTaskStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('crm.task_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
