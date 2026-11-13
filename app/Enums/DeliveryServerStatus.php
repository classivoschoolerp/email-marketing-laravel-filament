<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Delivery server lifecycle states (spec §2.9.2 F-2.9.2-042).
 */
enum DeliveryServerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case InProgress = 'in-progress';
    case Disabled = 'disabled';
    case HourlyQuotaReached = 'hourly-quota-reached';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('sending.status.active'),
            self::Inactive => __('sending.status.inactive'),
            self::InProgress => __('sending.status.in_progress'),
            self::Disabled => __('sending.status.disabled'),
            self::HourlyQuotaReached => __('sending.status.hourly_quota_reached'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'gray',
            self::InProgress => 'info',
            self::Disabled => 'danger',
            self::HourlyQuotaReached => 'warning',
        };
    }
}
