<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Subscriber lifecycle status (spec §2.2.4). `moved` marks the source row
 * after a move-to-list; `blacklisted` here is the per-row status — the global
 * suppression list arrives with §2.9.11.
 */
enum SubscriberStatus: string
{
    case Confirmed = 'confirmed';
    case Unconfirmed = 'unconfirmed';
    case Unsubscribed = 'unsubscribed';
    case Blacklisted = 'blacklisted';
    case Disabled = 'disabled';
    case Moved = 'moved';
    case Bounced = 'bounced';

    public function label(): string
    {
        return __('subscribers.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Confirmed => 'success',
            self::Unconfirmed => 'warning',
            self::Unsubscribed => 'gray',
            self::Blacklisted => 'danger',
            self::Disabled => 'gray',
            self::Moved => 'info',
            self::Bounced => 'danger',
        };
    }

    /** Statuses that count as an active list membership. */
    public function isActive(): bool
    {
        return in_array($this, [self::Confirmed, self::Unconfirmed], true);
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
