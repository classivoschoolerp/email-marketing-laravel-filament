<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Delivery server group flavour (spec §3.13.1 F-3.13.1-008/009): a plain
 * sending pool, or an IP pool that additionally owns the dedicated IP
 * addresses its member servers send from.
 */
enum DeliveryServerGroupType: string
{
    case Pool = 'pool';
    case IpPool = 'ip_pool';

    public function label(): string
    {
        return match ($this) {
            self::Pool => __('sending.groups.type.pool'),
            self::IpPool => __('sending.groups.type.ip_pool'),
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
