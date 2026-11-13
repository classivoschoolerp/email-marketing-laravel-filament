<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which check produced a deliverability alert (spec §2.9.9 F-2.9.9-006/007).
 */
enum DeliverabilityAlertType: string
{
    case Reputation = 'reputation';
    case Blacklist = 'blacklist';
    case Authentication = 'authentication';
    case Engagement = 'engagement';

    public function label(): string
    {
        return match ($this) {
            self::Reputation => __('deliverability.alert_type.reputation'),
            self::Blacklist => __('deliverability.alert_type.blacklist'),
            self::Authentication => __('deliverability.alert_type.authentication'),
            self::Engagement => __('deliverability.alert_type.engagement'),
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
