<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Action applied to a subscriber on a hard bounce, or once the N-soft-bounces
 * threshold is reached (spec §2.9.4 F-2.9.4-007/008). Every bounce always
 * records the bounce fields; MarkBounced is the baseline action (status →
 * bounced), the others go further.
 */
enum BounceAction: string
{
    case MarkBounced = 'mark-bounced';
    case Unsubscribe = 'unsubscribe';
    case Blacklist = 'blacklist';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::MarkBounced => __('infra.bounce_action.mark_bounced'),
            self::Unsubscribe => __('infra.bounce_action.unsubscribe'),
            self::Blacklist => __('infra.bounce_action.blacklist'),
            self::Delete => __('infra.bounce_action.delete'),
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
