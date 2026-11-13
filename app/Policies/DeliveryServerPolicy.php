<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\User;
use App\Services\Sending\DeliveryServerQuota;
use App\Support\Roles\RoleChecker;

/**
 * Delivery server access rules (spec §2.9.2, WP-14).
 *
 * Customers: full control of their own UNLOCKED rows; system rows assigned to
 * their group are strictly read-only (F-2.9.2-037), and rows an admin locked
 * are read-only too (F-2.9.2-040). Creation is gated by the
 * DeliveryServerQuota chain (permission + count; the per-type whitelist is
 * enforced in the creation paths where the type is known).
 *
 * Admin users need servers.manage (Super Admin passes via Gate::before); the
 * full admin resource is WP-15.
 */
class DeliveryServerPolicy
{
    public const ADMIN_PERMISSION = 'servers.manage';

    public function __construct(
        private readonly RoleChecker $checker,
        private readonly DeliveryServerQuota $quota,
    ) {}

    public function viewAny(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return true;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User|Customer $user, DeliveryServer $server): bool
    {
        if ($user instanceof Customer) {
            return $server->isVisibleToCustomer($user);
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function create(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return $this->quota->canCreate($user);
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function update(User|Customer $user, DeliveryServer $server): bool
    {
        if ($user instanceof Customer) {
            return $server->isEditableByCustomer($user);
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function delete(User|Customer $user, DeliveryServer $server): bool
    {
        return $this->update($user, $server);
    }

    public function restore(User|Customer $user, DeliveryServer $server): bool
    {
        return $this->update($user, $server);
    }

    public function forceDelete(User|Customer $user, DeliveryServer $server): bool
    {
        return $this->update($user, $server);
    }
}
