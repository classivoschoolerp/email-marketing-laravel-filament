<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sandbox/live switch per payment gateway (§3.26.3 F-3.26.3-006).
 */
enum GatewayMode: string
{
    case Sandbox = 'sandbox';
    case Live = 'live';

    public function label(): string
    {
        return __('billing.gateways.mode.'.$this->value);
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
