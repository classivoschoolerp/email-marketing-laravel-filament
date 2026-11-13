<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How often a subscriber agreed to be emailed, chosen on the public
 * preference center (spec §1.5 F-1.5-012). Stored on
 * `subscribers.send_frequency`; a NULL column means "never asked" and is
 * equivalent to {@see self::EverySend}.
 *
 * `Paused` is a temporary opt-out: it is paired with
 * `subscribers.frequency_paused_until`, so the pause lapses on its own.
 */
enum SendFrequency: string
{
    case EverySend = 'every_send';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Paused = 'paused';

    public function label(): string
    {
        return __('public.lists.frequencies.'.$this->value);
    }

    /**
     * Minimum number of days that must pass between two campaigns for a
     * subscriber holding this preference; 0 = no throttle. This is the
     * number the send path needs (see the class docblock of
     * App\Services\Lists\SubscriberPreferences for the exact wiring).
     */
    public function minimumDaysBetweenSends(): int
    {
        return match ($this) {
            self::EverySend, self::Paused => 0,
            self::Weekly => 7,
            self::Monthly => 30,
        };
    }

    /** The preference asks for a pause window (F-1.5-012 "pause for N days"). */
    public function isPause(): bool
    {
        return $this === self::Paused;
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
