<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Domain;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminActors;
use App\Support\Roles\RoleChecker;

/**
 * Brand domains (spec §6.4) behind the coarse `domains.manage` admin
 * permission.
 *
 * A brand domain decides which host serves which content, which from-address
 * signs outgoing mail and whether registration is open — that is operator
 * territory in every deployment, including agency mode where a row is
 * ASSIGNED to a customer. The assignment is what a tenant gets; creating and
 * re-pointing the row is not.
 *
 * Super Admin passes through the global Gate::before (AppServiceProvider).
 */
class DomainPolicy
{
    use DeniesNonAdminActors;

    public const ADMIN_PERMISSION = 'domains.manage';

    public function viewAny(User $user): bool
    {
        return app(RoleChecker::class)->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User $user, Domain $domain): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Domain $domain): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Domain $domain): bool
    {
        return $this->viewAny($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }
}
