<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerNote;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminActors;
/**
 * Internal admin notes on customers (spec §3.4 F-3.4-012): visible with
 * customers.view, writable with customers.update/customers.manage.
 */
use App\Support\Roles\RoleChecker;

class CustomerNotePolicy
{
    use DeniesNonAdminActors;

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.view');
    }

    public function view(User $user, CustomerNote $note): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, CustomerNote $note): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, CustomerNote $note): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'customers.update')
            || $this->checker->hasActivePermission($user, 'customers.manage');
    }
}
