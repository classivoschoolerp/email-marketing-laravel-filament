<?php

declare(strict_types=1);

namespace App\Support\Roles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Role/permission enforcement helper for the three-tier model (spec §0.3).
 *
 * Reads assignments straight from the permission tables so it works for any
 * authenticatable (User or Customer) and — unlike spatie's trait methods —
 * ignores roles that have been soft-disabled (disabled_at != null) while
 * leaving the assignment rows untouched (F-0.3-010).
 *
 * Registered as a scoped singleton; results are memoised per request. Call
 * flush() after mutating roles/assignments mid-request (model events on
 * App\Models\Role do this automatically for role rows).
 */
class RoleChecker
{
    /** @var array<string, list<string>> */
    private array $roleMemo = [];

    /** @var array<string, list<string>> */
    private array $permissionMemo = [];

    /** @return list<string> active (non-disabled) role names assigned to the model */
    public function activeRoleNames(Model $user): array
    {
        $key = $this->memoKey($user);

        return $this->roleMemo[$key] ??= DB::table(config('permission.table_names.model_has_roles'))
            ->join(
                config('permission.table_names.roles'),
                config('permission.table_names.roles').'.id',
                '=',
                config('permission.table_names.model_has_roles').'.role_id'
            )
            ->where('model_type', $user->getMorphClass())
            ->where(config('permission.column_names.model_morph_key'), $user->getKey())
            ->whereNull('disabled_at')
            ->pluck(config('permission.table_names.roles').'.name')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    public function hasActiveRole(Model $user, string $role): bool
    {
        return in_array($role, $this->activeRoleNames($user), true);
    }

    /** @return list<string> permission names granted via active roles or directly */
    public function activePermissionNames(Model $user): array
    {
        $key = $this->memoKey($user);

        if (isset($this->permissionMemo[$key])) {
            return $this->permissionMemo[$key];
        }

        $tables = config('permission.table_names');
        $morphKey = config('permission.column_names.model_morph_key');

        $viaRoles = DB::table($tables['model_has_roles'])
            ->join($tables['roles'], $tables['roles'].'.id', '=', $tables['model_has_roles'].'.role_id')
            ->join($tables['role_has_permissions'], $tables['role_has_permissions'].'.role_id', '=', $tables['roles'].'.id')
            ->join($tables['permissions'], $tables['permissions'].'.id', '=', $tables['role_has_permissions'].'.permission_id')
            ->where($tables['model_has_roles'].'.model_type', $user->getMorphClass())
            ->where($tables['model_has_roles'].'.'.$morphKey, $user->getKey())
            ->whereNull($tables['roles'].'.disabled_at')
            ->pluck($tables['permissions'].'.name');

        $direct = DB::table($tables['model_has_permissions'])
            ->join($tables['permissions'], $tables['permissions'].'.id', '=', $tables['model_has_permissions'].'.permission_id')
            ->where($tables['model_has_permissions'].'.model_type', $user->getMorphClass())
            ->where($tables['model_has_permissions'].'.'.$morphKey, $user->getKey())
            ->pluck($tables['permissions'].'.name');

        return $this->permissionMemo[$key] = $viaRoles->merge($direct)
            ->unique()
            ->map(fn ($name): string => (string) $name)
            ->values()
            ->all();
    }

    public function hasActivePermission(Model $user, string $permission): bool
    {
        return in_array($permission, $this->activePermissionNames($user), true);
    }

    public function flush(): void
    {
        $this->roleMemo = [];
        $this->permissionMemo = [];
    }

    private function memoKey(Model $user): string
    {
        return $user->getMorphClass().':'.$user->getKey();
    }
}
