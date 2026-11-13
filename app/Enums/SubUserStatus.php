<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * §2.15.4 team member lifecycle (WP-39).
 *
 * Pending  — invited, no password yet; cannot authenticate.
 * Active   — accepted the invitation; can log in on the customer guard.
 * Suspended— temporarily blocked by the account owner; row and grants kept.
 * Revoked  — invitation withdrawn / access removed; terminal, cannot log in.
 */
enum SubUserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    public function label(): string
    {
        return __('team.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Pending => 'warning',
            self::Suspended => 'gray',
            self::Revoked => 'danger',
        };
    }

    /** Only an accepted, non-blocked member may authenticate. */
    public function canLogin(): bool
    {
        return $this === self::Active;
    }

    /** Does this member still occupy a seat against max_sub_users? */
    public function occupiesSeat(): bool
    {
        return in_array($this, [self::Pending, self::Active, self::Suspended], true);
    }
}
