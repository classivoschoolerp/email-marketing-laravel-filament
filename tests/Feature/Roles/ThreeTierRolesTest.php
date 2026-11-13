<?php

declare(strict_types=1);

use App\Enums\RoleScope;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Support\Roles\RoleChecker;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Models\Permission;

// F-0.3-001 — spatie/laravel-permission wired through the extended Role model
it('wires spatie roles and permissions through the extended role model', function (): void {
    $this->seed(RoleSeeder::class);

    expect(config('permission.models.role'))->toBe(Role::class);

    $role = Role::findByName((string) config('emp.roles.admin'), 'admin');

    expect($role)->toBeInstanceOf(Role::class)
        ->and($role->hasPermissionTo('customers.manage'))->toBeTrue();
});

// F-0.3-002 + F-0.3-007 — locked admin system roles seeded
it('seeds the locked system roles for both guards', function (): void {
    $this->seed(RoleSeeder::class);

    foreach (['Super Admin', 'Admin', 'Support', 'Read-only'] as $name) {
        $role = Role::query()->where('name', $name)->where('guard_name', 'admin')->first();

        expect($role)->not->toBeNull()
            ->and($role->locked)->toBeTrue()
            ->and($role->scope)->toBe(RoleScope::System);
    }

    $owner = Role::query()->where('name', 'Customer Owner')->where('guard_name', 'customer')->first();

    expect($owner)->not->toBeNull()
        ->and($owner->locked)->toBeTrue()
        ->and($owner->scope)->toBe(RoleScope::System);
});

// F-0.3-008 — operator-defined global roles shared across the deployment
it('supports operator-defined global roles', function (): void {
    $role = Role::create(['name' => 'Compliance', 'guard_name' => 'admin']);

    expect($role->scope)->toBe(RoleScope::Global)
        ->and($role->customer_id)->toBeNull()
        ->and($role->locked)->toBeFalse();

    [$userA, $userB] = User::factory()->count(2)->create();
    $role->users()->attach([$userA->getKey(), $userB->getKey()]);

    $checker = app(RoleChecker::class);

    expect($checker->hasActiveRole($userA, 'Compliance'))->toBeTrue()
        ->and($checker->hasActiveRole($userB, 'Compliance'))->toBeTrue();
});

// F-0.3-009 — per-customer custom roles, names unique per tenant only
it('lets each tenant define custom roles with overlapping names', function (): void {
    [$tenantA, $tenantB] = Customer::factory()->count(2)->create();

    $roleA = Role::create(['name' => 'Manager', 'guard_name' => 'customer', 'customer_id' => $tenantA->id]);
    $roleB = Role::create(['name' => 'Manager', 'guard_name' => 'customer', 'customer_id' => $tenantB->id]);

    expect($roleA->scope)->toBe(RoleScope::Customer)
        ->and($roleB->scope)->toBe(RoleScope::Customer)
        ->and(Role::query()->where('name', 'Manager')->count())->toBe(2);

    // Same tenant may not duplicate a role name.
    expect(fn () => Role::create(['name' => 'Manager', 'guard_name' => 'customer', 'customer_id' => $tenantA->id]))
        ->toThrow(RoleAlreadyExists::class);
});

it('blocks duplicate system/global role names at the application layer', function (): void {
    Role::create(['name' => 'Compliance', 'guard_name' => 'admin']);

    expect(fn () => Role::create(['name' => 'Compliance', 'guard_name' => 'admin']))
        ->toThrow(RoleAlreadyExists::class);
});

// F-0.3-010 — disabling then re-enabling preserves assignments
it('preserves role assignments across disable and re-enable', function (): void {
    $user = User::factory()->create();

    $role = Role::create(['name' => 'Ops', 'guard_name' => 'admin']);
    $role->givePermissionTo(Permission::findOrCreate('customers.view', 'admin'));
    $role->users()->attach($user->getKey());

    $checker = app(RoleChecker::class);

    expect($checker->hasActiveRole($user, 'Ops'))->toBeTrue()
        ->and($checker->hasActivePermission($user, 'customers.view'))->toBeTrue();

    $role->disable();

    // The assignment row survives …
    expect(DB::table('model_has_roles')->where('role_id', $role->id)->count())->toBe(1)
        // … but the role grants nothing while disabled.
        ->and($checker->hasActiveRole($user, 'Ops'))->toBeFalse()
        ->and($checker->hasActivePermission($user, 'customers.view'))->toBeFalse()
        ->and($role->fresh()->isDisabled())->toBeTrue();

    $role->enable();

    expect($checker->hasActiveRole($user, 'Ops'))->toBeTrue()
        ->and($checker->hasActivePermission($user, 'customers.view'))->toBeTrue();
});
