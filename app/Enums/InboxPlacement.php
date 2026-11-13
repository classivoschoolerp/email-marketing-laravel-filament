<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a seed message landed (spec §2.9.9 F-2.9.9-004, WP-46).
 *
 * `Missing` is a real, distinct outcome — a message that never arrived was
 * neither delivered nor filed as spam, and collapsing it into "spam" would
 * overstate spam placement while hiding an outright block or a bounce.
 * `Pending` means the polling window is still open, not "we don't know".
 */
enum InboxPlacement: string
{
    case Pending = 'pending';
    case Inbox = 'inbox';
    case Spam = 'spam';
    case Missing = 'missing';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('deliverability.placement.placements.pending'),
            self::Inbox => __('deliverability.placement.placements.inbox'),
            self::Spam => __('deliverability.placement.placements.spam'),
            self::Missing => __('deliverability.placement.placements.missing'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Inbox => 'success',
            self::Spam => 'danger',
            self::Missing => 'warning',
            self::Pending => 'gray',
        };
    }

    public function isResolved(): bool
    {
        return $this !== self::Pending;
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
