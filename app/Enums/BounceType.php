<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bounce classification (spec §2.9.4 F-2.9.4-006): hard = permanent failure,
 * soft = transient failure, internal = not a real bounce (auto-replies,
 * delivery-delay warnings, unclassifiable chatter) — logged but never acted
 * on against the subscriber.
 */
enum BounceType: string
{
    case Hard = 'hard';
    case Soft = 'soft';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Hard => __('infra.bounce_type.hard'),
            self::Soft => __('infra.bounce_type.soft'),
            self::Internal => __('infra.bounce_type.internal'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Hard => 'danger',
            self::Soft => 'warning',
            self::Internal => 'gray',
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
