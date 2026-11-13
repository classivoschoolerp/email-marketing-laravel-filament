<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\RoleScope;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;

/**
 * WP-03 test helpers: admin actors with precise permission sets.
 */
final class AdminTestHelpers
{
    /** @param list<string> $permissions */
    public static function admin(array $permissions = []): User
    {
        $user = User::factory()->create();

        if ($permissions !== []) {
            $role = Role::create(['name' => 'WP03 Role '.uniqid(), 'guard_name' => 'admin']);

            $role->syncPermissions(array_map(
                fn (string $name): Permission => Permission::findOrCreate($name, 'admin'),
                $permissions,
            ));

            $role->users()->attach($user->getKey());
        }

        return $user;
    }

    /** @param list<string> $permissions */
    public static function actingAdmin(array $permissions = []): User
    {
        $user = self::admin($permissions);

        test()->actingAs($user, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    public static function superAdmin(): User
    {
        $user = User::factory()->create();

        $role = Role::query()->firstOrCreate(
            ['name' => (string) config('emp.roles.super_admin'), 'guard_name' => 'admin', 'customer_id' => null],
            ['scope' => RoleScope::System, 'locked' => true],
        );

        $role->users()->attach($user->getKey());

        return $user;
    }
}
