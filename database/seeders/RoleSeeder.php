<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleScope;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the locked system roles (spec §0.3 F-0.3-002/007, D-06) and the
 * delegation/impersonation permission set (F-0.3-011/012). Idempotent.
 */
class RoleSeeder extends Seeder
{
    /** Delegation areas per spec §0.3: customers, plans, sending servers, billing. */
    private const ADMIN_PERMISSIONS = [
        'roles.view',
        'roles.manage',
        'customers.view',
        'customers.manage',
        'customers.impersonate',
        'plans.view',
        'plans.manage',
        'servers.view',
        'servers.manage',
        'billing.view',
        'billing.manage',
    ];

    public function run(): void
    {
        foreach (self::ADMIN_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'admin');
        }

        // DatabaseSeeder applies WithoutModelEvents, which mutes spatie's
        // cache-forget hook: without this flush the syncPermissions() calls
        // below resolve against the pre-seed (empty) cache and throw
        // PermissionDoesNotExist on a fresh database.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = $this->systemRole((string) config('emp.roles.super_admin'), 'admin');
        // Super Admin is granted everything through Gate::before — no explicit
        // permission rows needed.

        $admin = $this->systemRole((string) config('emp.roles.admin'), 'admin');
        $admin->syncPermissions(self::ADMIN_PERMISSIONS);

        $support = $this->systemRole((string) config('emp.roles.support'), 'admin');
        $support->syncPermissions([
            'customers.view',
            (string) config('emp.impersonation.permission'),
        ]);

        $readOnly = $this->systemRole((string) config('emp.roles.read_only'), 'admin');
        $readOnly->syncPermissions([
            'roles.view', 'customers.view', 'plans.view', 'servers.view', 'billing.view',
        ]);

        $this->systemRole((string) config('emp.roles.customer_owner'), 'customer');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function systemRole(string $name, string $guard): Role
    {
        /** @var Role $role */
        $role = Role::query()->firstOrCreate(
            ['name' => $name, 'guard_name' => $guard, 'customer_id' => null],
            ['scope' => RoleScope::System, 'locked' => true],
        );

        if (! $role->locked || $role->scope !== RoleScope::System) {
            $role->forceFill(['locked' => true, 'scope' => RoleScope::System])->save();
        }

        return $role;
    }
}
