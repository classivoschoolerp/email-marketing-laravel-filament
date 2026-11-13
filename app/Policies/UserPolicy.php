<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminActors;
use App\Support\Roles\RoleChecker;
/**
 * Admin users module policy (spec §3.2). Matrix permissions users.* per
 * §3.3; deletion additionally guards against self-deletion and removing the
 * last Super Admin (F-3.2-004) — those denials are absolute, so
 * Gate::before (SuperAdminGate) must not bypass them: they are re-checked
 * here AND in the resource action, and the last-super-admin/self rules run
 * before the permission check.
 */
use Illuminate\Support\Facades\DB;

class UserPolicy
{
    use DeniesNonAdminActors;

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->checker->hasActivePermission($user, 'users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $this->checker->hasActivePermission($user, 'users.update');
    }

    public function delete(User $user, User $target): bool
    {
        if (! self::deletionAllowed($user, $target)) {
            return false;
        }

        return $this->checker->hasActivePermission($user, 'users.delete');
    }

    /**
     * Structural deletion guards (F-3.2-004): never yourself, never the last
     * Super Admin. Public so the Filament action can re-check even for Super
     * Admins (whose Gate::before short-circuits policies).
     */
    public static function deletionAllowed(User $actor, User $target): bool
    {
        if ($actor->getKey() === $target->getKey()) {
            return false;
        }

        return ! (self::isSuperAdmin($target) && self::superAdminCount() <= 1);
    }

    private static function isSuperAdmin(User $user): bool
    {
        return app(RoleChecker::class)->hasActiveRole($user, (string) config('emp.roles.super_admin'));
    }

    private static function superAdminCount(): int
    {
        $role = Role::query()
            ->where('name', (string) config('emp.roles.super_admin'))
            ->where('guard_name', 'admin')
            ->whereNull('disabled_at')
            ->first();

        if ($role === null) {
            return 0;
        }

        return (int) DB::table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $role->getKey())
            ->where('model_type', (new User)->getMorphClass())
            ->count();
    }
}
