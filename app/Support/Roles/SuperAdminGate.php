<?php

declare(strict_types=1);

namespace App\Support\Roles;

use App\Models\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Gate::before hook (spec §0.3): Super Admin is granted everything — except
 * mutating locked system roles, where the check falls through to RolePolicy
 * so "cannot be edited or deleted" stays absolute (F-0.3-007), and — WP-03
 * (spec §3.2 F-3.2-004) — deleting yourself or the last Super Admin, where
 * it falls through to UserPolicy for the same absolute denial.
 */
class SuperAdminGate
{
    private const LOCKED_ROLE_ABILITIES = [
        'update', 'edit', 'delete', 'destroy', 'forceDelete', 'restore',
    ];

    private const USER_DELETE_ABILITIES = [
        'delete', 'destroy', 'forceDelete',
    ];

    public function __construct(private readonly RoleChecker $checker) {}

    /** @param array<int, mixed> $arguments */
    public function handle(mixed $user, string $ability, array $arguments = []): ?bool
    {
        if (! $user instanceof Model) {
            return null;
        }

        if (! $this->checker->hasActiveRole($user, (string) config('emp.roles.super_admin'))) {
            return null;
        }

        $subject = $arguments[0] ?? null;

        if (
            $subject instanceof Role
            && $subject->locked
            && in_array($ability, self::LOCKED_ROLE_ABILITIES, true)
        ) {
            return null; // fall through to RolePolicy, which denies mutations on locked roles
        }

        if (
            $subject instanceof User
            && $user instanceof User
            && in_array($ability, self::USER_DELETE_ABILITIES, true)
            && ! UserPolicy::deletionAllowed($user, $subject)
        ) {
            return null; // fall through to UserPolicy, which denies self/last-Super-Admin deletion
        }

        return true;
    }
}
