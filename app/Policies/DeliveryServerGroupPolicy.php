<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\DeliveryServerGroup;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Delivery server group / pool access (spec §3.13.1 F-3.13.1-008/009,
 * WP-33). Pools shape where a tenant's traffic goes, so only admins with
 * servers.manage create or edit them; customers may read pools that apply to
 * them and never write.
 */
class DeliveryServerGroupPolicy
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

    public function view(User|Customer $user, DeliveryServerGroup $group): bool
    {
        if ($user instanceof Customer) {
            return $group->isSystem() || (int) $group->customer_id === (int) $user->id;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function create(User|Customer $user): bool
    {
        return $user instanceof User
            && $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function update(User|Customer $user, DeliveryServerGroup $group): bool
    {
        return $this->create($user);
    }

    public function delete(User|Customer $user, DeliveryServerGroup $group): bool
    {
        return $this->create($user);
    }
}
