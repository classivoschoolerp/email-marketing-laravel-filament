<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleScope;
use App\Support\Admin\AdminModules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin user group (spec §3.3). The fine-grained view/create/update/delete
 * permission matrix over the admin modules is stored as spatie permissions
 * attached to a dedicated group role (guard admin, scope global). Assigning a
 * user to the group attaches that role, so RoleChecker/policies enforce the
 * matrix at route level (F-3.3-002) with no extra plumbing.
 */
class UserGroup extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Deleting a group must revoke its matrix: the backing role goes
        // with it (spatie FKs cascade the assignment/permission pivots), so
        // former members cannot keep stale access.
        static::deleted(function (self $group): void {
            $group->role()->first()?->delete();
        });
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Creates the backing role on first use. Role names are namespaced so
     * they never collide with operator-defined delegation roles (D-17).
     */
    public function ensureRole(): Role
    {
        if ($this->role !== null) {
            return $this->role;
        }

        $role = Role::create([
            'name' => 'user-group:'.$this->getKey(),
            'guard_name' => 'admin',
            'scope' => RoleScope::Global,
        ]);

        $this->forceFill(['role_id' => $role->getKey()])->save();
        $this->setRelation('role', $role);

        return $role;
    }

    /**
     * Replaces the group's permission matrix. Accepts spatie permission names
     * (admin guard); unknown names are created on the fly so the matrix and
     * any pre-existing delegation permissions share one store.
     *
     * @param  list<string>  $permissions
     */
    public function syncMatrix(array $permissions): void
    {
        $role = $this->ensureRole();

        $role->syncPermissions(array_map(
            fn (string $name): Permission => Permission::findOrCreate($name, 'admin'),
            array_values(array_unique($permissions)),
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<string> permission names currently granted by the matrix */
    public function matrixPermissions(): array
    {
        if ($this->role === null) {
            return [];
        }

        return $this->role->permissions()->pluck('name')->map(fn ($n): string => (string) $n)->all();
    }

    /** @return list<string> only the module-matrix subset (view/create/update/delete) */
    public function moduleMatrixPermissions(): array
    {
        $matrix = AdminModules::allPermissions();

        return array_values(array_intersect($this->matrixPermissions(), $matrix));
    }
}
