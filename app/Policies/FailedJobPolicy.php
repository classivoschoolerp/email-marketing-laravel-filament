<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\FailedJob;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Failed queue jobs (spec §0.4 F-0.4-003 / §3.27 F-3.27-012, WP-17):
 * inspection needs `logs.view`; retry/delete mutate the queue and need
 * `logs.manage`. Admin guard only; Super Admin passes via Gate::before.
 */
class FailedJobPolicy
{
    public const VIEW_PERMISSION = 'logs.view';

    public const MANAGE_PERMISSION = 'logs.manage';

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof User
            && $this->checker->hasActivePermission($user, self::VIEW_PERMISSION);
    }

    public function view(User|Customer $user, FailedJob $job): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|Customer $user): bool
    {
        return false; // written by the queue worker only
    }

    public function update(User|Customer $user, FailedJob $job): bool
    {
        return $user instanceof User
            && $this->checker->hasActivePermission($user, self::MANAGE_PERMISSION);
    }

    public function delete(User|Customer $user, FailedJob $job): bool
    {
        return $this->update($user, $job);
    }
}
