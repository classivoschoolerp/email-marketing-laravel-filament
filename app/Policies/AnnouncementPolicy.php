<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Announcement;
use App\Models\Customer;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Announcements (spec §2.1 F-2.1-010, WP-18) are admin-authored notices to
 * customers — the same delegation area as the customers module, so the D-42
 * matrix `customers.*` permissions gate them: view to read, update to
 * author/retire. Customers never manage this model — the dashboard widget
 * reads it through its own scoped queries. Super Admin passes via
 * Gate::before.
 */
class AnnouncementPolicy
{
    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof User
            && $this->checker->hasActivePermission($user, 'customers.view');
    }

    public function view(User|Customer $user, Announcement $announcement): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|Customer $user): bool
    {
        return $user instanceof User
            && $this->checker->hasActivePermission($user, 'customers.update');
    }

    public function update(User|Customer $user, Announcement $announcement): bool
    {
        return $this->create($user);
    }

    public function delete(User|Customer $user, Announcement $announcement): bool
    {
        return $this->create($user);
    }
}
