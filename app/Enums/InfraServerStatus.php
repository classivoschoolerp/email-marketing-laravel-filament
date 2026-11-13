<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bounce / FBL server lifecycle (spec §2.9.4/§2.9.5): only active servers are
 * polled by the bounces:process / fbl:process crons.
 */
enum InfraServerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('infra.server_status.active'),
            self::Inactive => __('infra.server_status.inactive'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'gray',
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
