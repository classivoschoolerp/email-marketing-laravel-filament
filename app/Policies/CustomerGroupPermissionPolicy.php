<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerGroupPermission;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Feature-permission matrix rows are operator data: admins with
 * customers.manage only (spec §0.3 F-0.3-004). Super Admin passes via
 * Gate::before.
 */
class CustomerGroupPermissionPolicy
{
    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.view');
    }

    public function view(User $user, CustomerGroupPermission $permission): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.view');
    }

    public function create(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.manage');
    }

    public function update(User $user, CustomerGroupPermission $permission): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.manage');
    }

    public function delete(User $user, CustomerGroupPermission $permission): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.manage');
    }
}
