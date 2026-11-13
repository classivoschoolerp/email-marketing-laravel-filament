<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\ListSegment;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * List segments (spec §2.2.10) — tenant-owned CRUD.
 */
class ListSegmentPolicy
{
    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'lists.view');
    }

    public function view(User|Customer $user, ListSegment $segment): bool
    {
        if ($user instanceof Customer) {
            return $segment->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'lists.view');
    }

    public function create(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'lists.update');
    }

    public function update(User|Customer $user, ListSegment $segment): bool
    {
        if ($user instanceof Customer) {
            return $segment->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'lists.update');
    }

    public function delete(User|Customer $user, ListSegment $segment): bool
    {
        if ($user instanceof Customer) {
            return $segment->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'lists.update');
    }

    public function deleteAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'lists.update');
    }
}
