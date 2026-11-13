<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a matched monitor condition does (spec §2.9.6 F-2.9.6-003).
 * Subscriber-touching actions always run THROUGH the model so the list's
 * subscriber-actions cascade fires (same rule as BounceProcessor).
 */
enum MonitorAction: string
{
    case Unsubscribe = 'unsubscribe';
    case Blacklist = 'blacklist';
    case DeleteSubscriber = 'delete_subscriber';
    case MoveToList = 'move_to_list';
    case NotifyAdmin = 'notify_admin';

    public function label(): string
    {
        return match ($this) {
            self::Unsubscribe => __('infra.monitors.action.unsubscribe'),
            self::Blacklist => __('infra.monitors.action.blacklist'),
            self::DeleteSubscriber => __('infra.monitors.action.delete_subscriber'),
            self::MoveToList => __('infra.monitors.action.move_to_list'),
            self::NotifyAdmin => __('infra.monitors.action.notify_admin'),
        };
    }

    /** Does this action need a subscriber to act on? */
    public function needsSubscriber(): bool
    {
        return $this !== self::NotifyAdmin;
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
