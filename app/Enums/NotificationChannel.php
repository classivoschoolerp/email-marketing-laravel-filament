<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * §2.15.3 delivery channels a notification preference can toggle (WP-39).
 */
enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';

    public function label(): string
    {
        return __('notifications.channels.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
