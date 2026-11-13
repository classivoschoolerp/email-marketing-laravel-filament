<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

function giveRole(User $user, string $name): void
{
    Role::query()->where('name', $name)->where('guard_name', 'admin')->firstOrFail()
        ->users()->attach($user->getKey());
}

// F-0.3-011 — sub-administrators: delegate areas without full root access
it('delegates specific admin areas without granting root access', function (): void {
    $support = User::factory()->create();
    giveRole($support, 'Support');

    expect($support->can('customers.view'))->toBeTrue()
        ->and($support->can('customers.impersonate'))->toBeTrue()
        ->and($support->can('plans.manage'))->toBeFalse()
        ->and($support->can('servers.manage'))->toBeFalse()
        ->and($support->can('billing.manage'))->toBeFalse()
        ->and($support->can('roles.manage'))->toBeFalse();
});

it('supports read-only administrators', function (): void {
    $readOnly = User::factory()->create();
    giveRole($readOnly, 'Read-only');

    foreach (['roles.view', 'customers.view', 'plans.view', 'servers.view', 'billing.view'] as $view) {
        expect($readOnly->can($view))->toBeTrue();
    }

    foreach (['customers.manage', 'plans.manage', 'servers.manage', 'billing.manage', 'roles.manage', 'customers.impersonate'] as $manage) {
        expect($readOnly->can($manage))->toBeFalse();
    }
});

it('lets operators compose custom delegation roles from the permission set', function (): void {
    $billingAdmin = User::factory()->create();

    $role = Role::create(['name' => 'Billing Admin', 'guard_name' => 'admin']);
    $role->syncPermissions(['billing.view', 'billing.manage']);
    $role->users()->attach($billingAdmin->getKey());

    expect($billingAdmin->can('billing.manage'))->toBeTrue()
        ->and($billingAdmin->can('customers.manage'))->toBeFalse();
});

// F-0.3-012 — impersonation flag + permission definitions
it('defines the impersonation permission and config flag', function (): void {
    expect((bool) config('emp.impersonation.enabled'))->toBeTrue()
        ->and(config('emp.impersonation.permission'))->toBe('customers.impersonate')
        ->and(
            Permission::query()
                ->where('name', 'customers.impersonate')
                ->where('guard_name', 'admin')
                ->exists()
        )->toBeTrue();

    $support = User::factory()->create();
    $readOnly = User::factory()->create();
    giveRole($support, 'Support');
    giveRole($readOnly, 'Read-only');

    expect($support->can((string) config('emp.impersonation.permission')))->toBeTrue()
        ->and($readOnly->can((string) config('emp.impersonation.permission')))->toBeFalse();
});
