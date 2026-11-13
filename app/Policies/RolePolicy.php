<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminActors;
use App\Support\Roles\RoleChecker;

/**
 * Locked system roles can never be edited or deleted — not even by Super
 * Admin, whose Gate::before deliberately falls through to this policy for
 * locked-role mutations (spec §0.3 F-0.3-007).
 */
class RolePolicy
{
    use DeniesNonAdminActors;

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->checker->hasActivePermission($user, 'roles.view');
    }

    public function create(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'roles.manage');
    }

    public function update(User $user, Role $role): bool
    {
        return ! $role->locked && $this->checker->hasActivePermission($user, 'roles.manage');
    }

    public function delete(User $user, Role $role): bool
    {
        return ! $role->locked && $this->checker->hasActivePermission($user, 'roles.manage');
    }

    public function restore(User $user, Role $role): bool
    {
        return $this->update($user, $role);
    }

    public function forceDelete(User $user, Role $role): bool
    {
        return $this->delete($user, $role);
    }
}
