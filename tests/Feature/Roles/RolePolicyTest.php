<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

function attachRole(User $user, string $name): void
{
    Role::query()->where('name', $name)->where('guard_name', 'admin')->firstOrFail()
        ->users()->attach($user->getKey());
}

// F-0.3-007 — locked roles cannot be edited or deleted, not even by Super Admin
it('denies editing or deleting locked system roles even for super admin', function (): void {
    $superAdmin = User::factory()->create();
    attachRole($superAdmin, 'Super Admin');

    $locked = Role::query()->where('name', 'Admin')->where('guard_name', 'admin')->firstOrFail();

    expect(Gate::forUser($superAdmin)->allows('update', $locked))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('delete', $locked))->toBeFalse()
        // Super Admin still passes everything that is not a locked-role mutation.
        ->and(Gate::forUser($superAdmin)->allows('view', $locked))->toBeTrue()
        ->and(Gate::forUser($superAdmin)->allows('create', Role::class))->toBeTrue();
});

it('lets admins with roles.manage manage unlocked roles only', function (): void {
    $admin = User::factory()->create();
    attachRole($admin, 'Admin');

    $custom = Role::create(['name' => 'Ops', 'guard_name' => 'admin']);
    $locked = Role::query()->where('name', 'Support')->where('guard_name', 'admin')->firstOrFail();

    expect(Gate::forUser($admin)->allows('update', $custom))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $custom))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $locked))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $locked))->toBeFalse();
});

it('denies role management without the roles.manage permission', function (): void {
    $support = User::factory()->create();
    attachRole($support, 'Support');

    $custom = Role::create(['name' => 'Ops', 'guard_name' => 'admin']);

    expect(Gate::forUser($support)->allows('update', $custom))->toBeFalse()
        ->and(Gate::forUser($support)->allows('create', Role::class))->toBeFalse()
        ->and(Gate::forUser($support)->allows('viewAny', Role::class))->toBeFalse();
});

// Gate::before — Super Admin passes arbitrary abilities (spec §0.3)
it('grants super admin arbitrary gate abilities via Gate::before', function (): void {
    $superAdmin = User::factory()->create();
    $regular = User::factory()->create();
    attachRole($superAdmin, 'Super Admin');

    Gate::define('launch-missiles', fn (): bool => false);

    expect(Gate::forUser($superAdmin)->allows('launch-missiles'))->toBeTrue()
        ->and(Gate::forUser($regular)->allows('launch-missiles'))->toBeFalse();
});
