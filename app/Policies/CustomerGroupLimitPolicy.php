<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerGroupLimit;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Quota matrix rows are operator data: admins with customers.manage only
 * (spec §0.3 F-0.3-005). Super Admin passes via Gate::before.
 */
class CustomerGroupLimitPolicy
{
    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.view');
    }

    public function view(User $user, CustomerGroupLimit $limit): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.view');
    }

    public function create(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.manage');
    }

    public function update(User $user, CustomerGroupLimit $limit): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.manage');
    }

    public function delete(User $user, CustomerGroupLimit $limit): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.manage');
    }
}
