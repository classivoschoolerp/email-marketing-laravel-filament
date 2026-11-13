<?php

declare(strict_types=1);

use App\Enums\RoleScope;
use App\Filament\Admin\Resources\RoleResource;
use App\Filament\Admin\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Admin\Resources\RoleResource\Pages\EditRole;
use App\Filament\Admin\Resources\RoleResource\Pages\ListRoles;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

// F-0.3-006 — roles UI guarded by RolePolicy
it('guards the roles module', function (): void {
    $this->seed(RoleSeeder::class);

    AdminTestHelpers::actingAdmin(['roles.view']);
    $this->get(RoleResource::getUrl('index', panel: 'admin'))->assertOk();

    $this->actingAs(User::factory()->create(), 'admin');
    $this->get(RoleResource::getUrl('index', panel: 'admin'))->assertForbidden();

    $customer = \App\Models\Customer::factory()->create();
    expect(\Illuminate\Support\Facades\Gate::forUser($customer)->allows('viewAny', Role::class))->toBeFalse();
});

it('lists system and global admin roles but never customer-scoped roles', function (): void {
    $this->seed(RoleSeeder::class);
    AdminTestHelpers::actingAdmin(['roles.view']);

    $customer = \App\Models\Customer::factory()->create();
    $tenantRole = Role::create(['name' => 'Tenant Role', 'guard_name' => 'customer', 'customer_id' => $customer->id]);
    $system = Role::query()->where('name', (string) config('emp.roles.super_admin'))->where('guard_name', 'admin')->firstOrFail();

    Livewire::test(ListRoles::class)
        ->assertCanSeeTableRecords([$system])
        ->assertCanNotSeeTableRecords([$tenantRole]);
});

// F-0.3-011 — operators compose delegation roles from the permission set
it('creates a global delegation role with permissions', function (): void {
    $this->seed(RoleSeeder::class);
    AdminTestHelpers::actingAdmin(['roles.view', 'roles.manage']);

    Livewire::test(CreateRole::class)
        ->fillForm([
            'name' => 'Billing Desk',
            'permission_names' => ['billing.view', 'billing.manage'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('name', 'Billing Desk')->where('guard_name', 'admin')->firstOrFail();

    expect($role->scope)->toBe(RoleScope::Global)
        ->and($role->locked)->toBeFalse()
        ->and($role->permissions()->pluck('name')->all())
        ->toContain('billing.view', 'billing.manage');
});

// D-18 — locked system roles cannot be edited, even via the UI
it('denies editing locked system roles per RolePolicy', function (): void {
    $this->seed(RoleSeeder::class);
    AdminTestHelpers::actingAdmin(['roles.view', 'roles.manage']);

    $locked = Role::query()->where('name', (string) config('emp.roles.admin'))->where('guard_name', 'admin')->firstOrFail();

    $this->get(RoleResource::getUrl('edit', ['record' => $locked], panel: 'admin'))
        ->assertForbidden();

    // Unlocked global roles are editable.
    $editable = Role::create(['name' => 'Editable Role', 'guard_name' => 'admin']);

    Livewire::test(EditRole::class, ['record' => $editable->getRouteKey()])
        ->fillForm(['name' => 'Edited Role', 'permission_names' => ['plans.view']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($editable->fresh()->name)->toBe('Edited Role')
        ->and($editable->fresh()->permissions()->pluck('name')->all())->toBe(['plans.view']);
});

// F-0.3-010 — soft disable/enable preserving assignments
it('disables and enables a role from the table, preserving assignments', function (): void {
    $this->seed(RoleSeeder::class);
    AdminTestHelpers::actingAdmin(['roles.view', 'roles.manage']);

    $role = Role::create(['name' => 'Toggle Role', 'guard_name' => 'admin']);
    $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('plans.view', 'admin'));

    $member = User::factory()->create();
    $role->users()->attach($member->getKey());

    expect($member->can('plans.view'))->toBeTrue();

    Livewire::test(ListRoles::class)->callTableAction('disable', $role);

    app(\App\Support\Roles\RoleChecker::class)->flush();

    expect($role->fresh()->isDisabled())->toBeTrue()
        ->and($member->fresh()->can('plans.view'))->toBeFalse()
        // assignment row preserved
        ->and($role->users()->whereKey($member->getKey())->exists())->toBeTrue();

    Livewire::test(ListRoles::class)->callTableAction('enable', $role->fresh());

    app(\App\Support\Roles\RoleChecker::class)->flush();

    expect($member->fresh()->can('plans.view'))->toBeTrue();
});
