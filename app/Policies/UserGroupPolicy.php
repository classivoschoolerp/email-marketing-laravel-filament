<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserGroup;
use App\Policies\Concerns\DeniesNonAdminActors;
/**
 * Admin user-groups module policy (spec §3.3) — user_groups.* matrix keys.
 */
use App\Support\Roles\RoleChecker;

class UserGroupPolicy
{
    use DeniesNonAdminActors;

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'user_groups.view');
    }

    public function view(User $user, UserGroup $group): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'user_groups.create');
    }

    public function update(User $user, UserGroup $group): bool
    {
        return $this->checker->hasActivePermission($user, 'user_groups.update');
    }

    public function delete(User $user, UserGroup $group): bool
    {
        return $this->checker->hasActivePermission($user, 'user_groups.delete');
    }
}
