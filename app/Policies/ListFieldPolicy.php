<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\ListField;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * List custom fields (spec §2.2.9) — tenant-owned CRUD.
 */
class ListFieldPolicy
{
    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'lists.view');
    }

    public function view(User|Customer $user, ListField $field): bool
    {
        if ($user instanceof Customer) {
            return $field->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'lists.view');
    }

    public function create(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'lists.update');
    }

    public function update(User|Customer $user, ListField $field): bool
    {
        if ($user instanceof Customer) {
            return $field->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'lists.update');
    }

    public function delete(User|Customer $user, ListField $field): bool
    {
        if ($user instanceof Customer) {
            return $field->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'lists.update');
    }

    public function deleteAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'lists.update');
    }

    public function reorder(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'lists.update');
    }
}
