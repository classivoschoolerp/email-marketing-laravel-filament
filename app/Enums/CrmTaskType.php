<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * §2.13 F-2.13-004 — "Tasks, reminders, meeting scheduler". One table, three
 * kinds of row: a to-do, a call to make, and a scheduled meeting (which adds
 * a duration, a location/URL and an ICS export).
 */
enum CrmTaskType: string
{
    case Task = 'task';
    case Call = 'call';
    case Meeting = 'meeting';

    public function label(): string
    {
        return __('crm.task_type.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Task => 'heroicon-m-check-circle',
            self::Call => 'heroicon-m-phone',
            self::Meeting => 'heroicon-m-calendar-days',
        };
    }

    /** Only a meeting carries a duration/location and can be exported as ICS. */
    public function isScheduled(): bool
    {
        return $this === self::Meeting;
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
