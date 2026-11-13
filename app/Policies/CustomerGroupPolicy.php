<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerGroup;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminActors;
/**
 * Customer groups module policy (spec §3.5). Accepts the §3.3 granular
 * matrix keys (customer_groups.*) OR the §0.3 "plans" delegation area —
 * customer groups are the plan/quota carrier until billing plans arrive
 * (WP-23), so plans.view/plans.manage delegate this module.
 */
use App\Support\Roles\RoleChecker;

class CustomerGroupPolicy
{
    use DeniesNonAdminActors;

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'customer_groups.view')
            || $this->checker->hasActivePermission($user, 'plans.view');
    }

    public function view(User $user, CustomerGroup $group): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->checkEither($user, 'customer_groups.create');
    }

    public function update(User $user, CustomerGroup $group): bool
    {
        return $this->checkEither($user, 'customer_groups.update');
    }

    public function delete(User $user, CustomerGroup $group): bool
    {
        return $this->checkEither($user, 'customer_groups.delete');
    }

    private function checkEither(User $user, string $granular): bool
    {
        return $this->checker->hasActivePermission($user, $granular)
            || $this->checker->hasActivePermission($user, 'plans.manage');
    }
}
