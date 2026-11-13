<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\ImportJob;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Import history + error report access (spec §2.2.7). Customers only see
 * their own import runs.
 */
class ImportJobPolicy
{
    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'lists.view');
    }

    public function view(User|Customer $user, ImportJob $import): bool
    {
        if ($user instanceof Customer) {
            return $import->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'lists.view');
    }

    public function download(User|Customer $user, ImportJob $import): bool
    {
        return $this->view($user, $import);
    }

    /**
     * Re-running an import writes subscribers, so it needs the same right as
     * editing a list — never merely "can see the history" (F-2.2.7-013).
     */
    public function rerun(User|Customer $user, ImportJob $import): bool
    {
        if ($user instanceof Customer) {
            return $import->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'lists.update');
    }

    /** Rolling back deletes/reverts subscribers — same right as re-running. */
    public function rollback(User|Customer $user, ImportJob $import): bool
    {
        return $this->rerun($user, $import);
    }
}
