<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Recurring-campaign repeat interval (spec §2.3.2 F-2.3.2-019). CustomCron
 * uses a user-provided cron expression validated with Cron\CronExpression.
 */
enum RecurringInterval: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case CustomCron = 'custom-cron';

    public function label(): string
    {
        return __('campaigns.recurring.interval.'.$this->value);
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
