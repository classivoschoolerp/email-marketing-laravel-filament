<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerPermission;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminActors;
/**
 * Per-customer feature-permission override rows (spec §3.4 F-3.4-009):
 * operator data, mirrors CustomerGroupPermissionPolicy.
 */
use App\Support\Roles\RoleChecker;

class CustomerPermissionPolicy
{
    use DeniesNonAdminActors;

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.view');
    }

    public function view(User $user, CustomerPermission $permission): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, CustomerPermission $permission): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, CustomerPermission $permission): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.update')
            || $this->checker->hasActivePermission($user, 'customers.manage');
    }
}
