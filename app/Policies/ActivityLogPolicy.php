<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * §2.15.7 activity log (WP-39): read-only, tenant-owned, and readable by
 * anyone inside the tenant — a team member seeing what the account did is
 * the point of the feature, and no team capability narrows it. Nobody
 * writes or deletes rows through the UI; the log is append-only.
 *
 * WP-40 opened the ADMIN guard for the §3.27 F-3.27-011 audit viewer:
 * `logs.view` — the same key the other §3.27 log screens use — reads every
 * tenant's entries and their before/after diffs. Still strictly read-only on
 * both guards: an audit trail an operator can edit is not an audit trail.
 */
class ActivityLogPolicy
{
    public const ADMIN_PERMISSION = 'logs.view';

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        if ($user instanceof User) {
            return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
        }

        return true;
    }

    public function view(User|Customer $user, ActivityLog $log): bool
    {
        if ($user instanceof User) {
            return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
        }

        return (int) $log->customer_id === (int) $user->id;
    }

    public function create(User|Customer $user): bool
    {
        return false;
    }

    public function update(User|Customer $user, ActivityLog $log): bool
    {
        return false;
    }

    public function delete(User|Customer $user, ActivityLog $log): bool
    {
        return false;
    }
}
