<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\SubscriberSendTimeProfile;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Send Time Optimization profiles (spec §2.3.4 F-2.3.4-011, WP-46) —
 * tenant-owned.
 *
 * The permissions are the SUBSCRIBER permissions, not campaign ones: a profile
 * is derived personal behaviour data about one subscriber, so whoever may see
 * that subscriber may see their profile, and nobody else. Creation and refresh
 * are a queued recomputation, never a customer-authored write, which is why
 * `create` is denied outright for admins without subscribers.manage.
 */
class SubscriberSendTimeProfilePolicy
{
    public const ADMIN_PERMISSION = 'subscribers.manage';

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User|Customer $user, SubscriberSendTimeProfile $profile): bool
    {
        if ($user instanceof Customer) {
            return (int) $profile->customer_id === (int) $user->id;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function create(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function update(User|Customer $user, SubscriberSendTimeProfile $profile): bool
    {
        return $this->view($user, $profile);
    }

    public function delete(User|Customer $user, SubscriberSendTimeProfile $profile): bool
    {
        return $this->view($user, $profile);
    }
}
