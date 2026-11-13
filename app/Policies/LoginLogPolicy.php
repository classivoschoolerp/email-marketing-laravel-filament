<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\LoginLog;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Customers may see only their own login history (spec §2.15.2); admins need
 * the customers.view permission (Super Admin passes via Gate::before).
 */
class LoginLogPolicy
{
    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return true; // scoped to their own rows by the Security page query
        }

        // WP-03: the §3.2 per-user activity tab shows admin login logs, so
        // the users module permission qualifies alongside customers.view.
        return $this->checker->hasActivePermission($user, 'customers.view')
            || $this->checker->hasActivePermission($user, 'users.view');
    }

    public function view(User|Customer $user, LoginLog $log): bool
    {
        if ($user instanceof Customer) {
            return $log->model_type === $user->getMorphClass()
                && (int) $log->model_id === (int) $user->getKey();
        }

        return $this->viewAny($user);
    }

    public function create(User|Customer $user): bool
    {
        return false; // written by the system only
    }

    public function update(User|Customer $user, LoginLog $log): bool
    {
        return false;
    }

    public function delete(User|Customer $user, LoginLog $log): bool
    {
        return false;
    }
}
