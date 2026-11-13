<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\EmailBoxMonitor;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Email box monitor access (spec §2.9.6 / §3.13.x, WP-33). Mirrors
 * BounceServerPolicy: admin users need servers.manage; customers may read and
 * manage only their OWN unlocked rows, and global rows (customer_id NULL) are
 * admin-only.
 */
class EmailBoxMonitorPolicy
{
    public const ADMIN_PERMISSION = 'servers.manage';

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return true;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User|Customer $user, EmailBoxMonitor $monitor): bool
    {
        if ($user instanceof Customer) {
            return ! $monitor->isSystem() && (int) $monitor->customer_id === (int) $user->id;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function create(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return false; // mailbox infrastructure is provisioned by an admin
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function update(User|Customer $user, EmailBoxMonitor $monitor): bool
    {
        if ($user instanceof Customer) {
            return $monitor->isEditableByCustomer($user);
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function delete(User|Customer $user, EmailBoxMonitor $monitor): bool
    {
        return $this->update($user, $monitor);
    }
}
