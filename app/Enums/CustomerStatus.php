<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case PendingConfirm = 'pending-confirm';
    case PendingApprove = 'pending-approve';
    case Blocked = 'blocked';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('customers.status.active'),
            self::PendingConfirm => __('customers.status.pending_confirm'),
            self::PendingApprove => __('customers.status.pending_approve'),
            self::Blocked => __('customers.status.blocked'),
            self::Inactive => __('customers.status.inactive'),
        };
    }

    public function canLogin(): bool
    {
        return $this === self::Active;
    }
}
