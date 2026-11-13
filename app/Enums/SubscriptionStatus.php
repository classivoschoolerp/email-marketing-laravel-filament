<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Subscription lifecycle (WP-23; PastDue added by WP-26 for F-3.26.6-005).
 *
 * Active/Trial are the "current" states. PastDue means an automatic renewal
 * charge failed and the dunning retry schedule is still running — the plan
 * stays applied during dunning. Expired is terminal for the period: the
 * final dunning attempt failed and SubscriptionRenewer downgraded the
 * customer (plan limit overrides removed, group reverted to the default).
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trial = 'trial';
    case PastDue = 'past_due';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('billing.subscriptions.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Trial => 'info',
            self::PastDue => 'warning',
            self::Expired => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /** States that still grant the plan's entitlements. */
    public function isCurrent(): bool
    {
        return in_array($this, [self::Active, self::Trial, self::PastDue], true);
    }
}
