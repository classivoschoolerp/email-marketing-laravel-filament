<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Order lifecycle (§3.26.2 F-3.26.2-001).
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Complete = 'complete';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('billing.orders.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Complete => 'success',
            self::Failed => 'danger',
            self::Refunded => 'info',
            self::Cancelled => 'gray',
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
