<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a public "never email me again" request (spec §1.10 / §3.19):
 * pending → confirmed (signed email link) → approved (→ global blacklist)
 * or rejected.
 */
enum BlockRequestStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('public.block_requests.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    /** Statuses an admin may still approve. */
    public function isApprovable(): bool
    {
        return $this !== self::Approved;
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
