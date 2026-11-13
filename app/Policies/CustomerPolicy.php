<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminActors;
/**
 * Admin customers module policy (spec §3.4). viewAny reads customers.view —
 * the same permission WP-02's RoleSeeder grants the Admin/Support/Read-only
 * system roles — so the §3.3 matrix and the §0.3 delegation set share one
 * store. Mutations accept the granular matrix keys (customers.create/
 * update/delete) OR the coarse delegation key customers.manage.
 */
use App\Support\Roles\RoleChecker;

class CustomerPolicy
{
    use DeniesNonAdminActors;

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->checkEither($user, 'customers.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->checkEither($user, 'customers.update');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->checkEither($user, 'customers.delete');
    }

    /** Login-as (spec §3.4 F-3.4-006) — requires the impersonation flag. */
    public function impersonate(User $user, Customer $customer): bool
    {
        return $this->checker->hasActivePermission(
            $user,
            (string) config('emp.impersonation.permission', 'customers.impersonate'),
        );
    }

    private function checkEither(User $user, string $granular): bool
    {
        return $this->checker->hasActivePermission($user, $granular)
            || $this->checker->hasActivePermission($user, 'customers.manage');
    }
}
