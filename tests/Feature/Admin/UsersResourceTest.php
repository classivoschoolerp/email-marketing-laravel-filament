<?php

declare(strict_types=1);

use App\Enums\LoginResult;
use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Resources\UserResource\Pages\CreateUser;
use App\Filament\Admin\Resources\UserResource\Pages\EditUser;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\LoginLog;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

// F-3.2-001 — users index with group/status/last login/2FA
it('renders the users index with last login and 2fa state for permitted admins', function (): void {
    AdminTestHelpers::actingAdmin(['users.view']);

    $group = UserGroup::query()->create(['name' => 'Ops']);
    $user = User::factory()->create(['user_group_id' => $group->id]);
    $user->forceFill(['twofa_enabled' => true, 'twofa_secret' => 'SECRET'])->save();

    LoginLog::record('admin', $user, $user->email, LoginResult::Success);

    $this->get(UserResource::getUrl('index', panel: 'admin'))->assertOk();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user]);

    /** @var User $row */
    $row = ListUsers::getResource()::getEloquentQuery()
        ->addSelect(['last_login_at' => LoginLog::query()
            ->select('created_at')
            ->where('guard', 'admin')
            ->where('model_type', (new User)->getMorphClass())
            ->whereColumn('model_id', 'users.id')
            ->latest('created_at')
            ->limit(1),
        ])
        ->find($user->id);

    expect($row->last_login_at)->not->toBeNull()
        ->and($row->twofa_enabled)->toBeTrue()
        ->and($row->group->name)->toBe('Ops');
});

// Policy denial — non-permitted admin
it('denies the users index to non-permitted admins', function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    $this->get(UserResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

// Policy denial — customer-guard user
it('denies the users module to customer-guard users', function (): void {
    $customer = \App\Models\Customer::factory()->create();

    expect(Gate::forUser($customer)->allows('viewAny', User::class))->toBeFalse();

    // A customer session never reaches the admin panel: unauthenticated on
    // the admin guard, the request bounces to the admin login.
    $this->actingAs($customer, 'customer');
    $this->get(UserResource::getUrl('index', panel: 'admin'))->assertRedirect();
});

// F-3.2-002 — create with name/email/password/timezone/group/status
it('creates a user with profile fields and group', function (): void {
    AdminTestHelpers::actingAdmin(['users.view', 'users.create']);

    $group = UserGroup::query()->create(['name' => 'Support Desk']);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Jane Admin',
            'email' => 'jane@example.com',
            'password' => 'Str0ngPass!',
            'timezone' => 'Europe/Berlin',
            'user_group_id' => $group->id,
            'status' => User::STATUS_ACTIVE,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'jane@example.com')->firstOrFail();

    expect($user->timezone)->toBe('Europe/Berlin')
        ->and($user->user_group_id)->toBe($group->id)
        // group role attached automatically (F-3.3-003)
        ->and($user->roles()->pluck('roles.id')->all())->toContain($group->fresh()->role_id);
});

// F-3.2-003 — role + granular permission assignment
it('assigns roles and granular permissions from the user form', function (): void {
    $this->seed(RoleSeeder::class);
    AdminTestHelpers::actingAdmin(['users.view', 'users.create']);

    $supportRole = Role::query()->where('name', 'Support')->where('guard_name', 'admin')->firstOrFail();

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Delegated Admin',
            'email' => 'delegate@example.com',
            'password' => 'Str0ngPass!',
            'timezone' => 'UTC',
            'status' => User::STATUS_ACTIVE,
            'assigned_roles' => [(string) $supportRole->id],
            'direct_permissions' => ['billing.view'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'delegate@example.com')->firstOrFail();

    expect($user->can('customers.view'))->toBeTrue()   // via Support role
        ->and($user->can('billing.view'))->toBeTrue()  // direct
        ->and($user->can('billing.manage'))->toBeFalse();
});

// F-3.2-005 — impersonate-customer permission flag
it('toggles the impersonate-customer flag as a direct permission', function (): void {
    $this->seed(RoleSeeder::class);
    AdminTestHelpers::actingAdmin(['users.view', 'users.create', 'users.update']);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Impersonator',
            'email' => 'imp@example.com',
            'password' => 'Str0ngPass!',
            'timezone' => 'UTC',
            'status' => User::STATUS_ACTIVE,
            'can_impersonate' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'imp@example.com')->firstOrFail();

    expect($user->can('customers.impersonate'))->toBeTrue();

    // Edit form round-trips and clears the flag.
    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->assertFormSet(['can_impersonate' => true])
        ->fillForm(['can_impersonate' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->can('customers.impersonate'))->toBeFalse();
});

// F-3.2-004 — disable/enable + panel lockout
it('disables and enables a user, locking a disabled user out of the panel', function (): void {
    AdminTestHelpers::actingAdmin(['users.view', 'users.update']);

    $target = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->callTableAction('disable', $target);

    expect($target->fresh()->status)->toBe(User::STATUS_DISABLED);

    // Disabled admins cannot access the panel (canAccessPanel).
    $this->actingAs($target->fresh(), 'admin');
    $this->get('/admin')->assertForbidden();

    AdminTestHelpers::actingAdmin(['users.view', 'users.update']);

    Livewire::test(ListUsers::class)
        ->callTableAction('enable', $target->fresh());

    expect($target->fresh()->status)->toBe(User::STATUS_ACTIVE);
});

// F-3.2-004 — delete guards: never yourself, never the last Super Admin
it('never deletes yourself or the last super admin', function (): void {
    $this->seed(RoleSeeder::class);

    $superAdmin = AdminTestHelpers::superAdmin();
    $this->actingAs($superAdmin, 'admin');

    // Self-deletion denied — even for Super Admin (SuperAdminGate falls through).
    expect(Gate::forUser($superAdmin)->allows('delete', $superAdmin))->toBeFalse();

    // Last Super Admin cannot be deleted by anyone.
    $manager = AdminTestHelpers::admin(['users.view', 'users.delete']);

    expect(Gate::forUser($manager)->allows('delete', $superAdmin))->toBeFalse();

    // A second Super Admin makes the first deletable again.
    AdminTestHelpers::superAdmin();
    app(\App\Support\Roles\RoleChecker::class)->flush();

    expect(Gate::forUser($manager)->allows('delete', $superAdmin))->toBeTrue();

    // Plain users are deletable with users.delete.
    $plain = User::factory()->create();

    expect(Gate::forUser($manager)->allows('delete', $plain))->toBeTrue()
        ->and(Gate::forUser(AdminTestHelpers::admin(['users.view']))->allows('delete', $plain))->toBeFalse();
});

it('deletes a user through the table action when permitted', function (): void {
    AdminTestHelpers::actingAdmin(['users.view', 'users.delete']);

    $target = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->callTableAction('delete', $target);

    expect(User::query()->whereKey($target->getKey())->exists())->toBeFalse();
});

// F-3.2-006 — per-user activity log (login logs + impersonation audit)
it('shows the per-user activity relation managers on the edit page', function (): void {
    $this->seed(RoleSeeder::class);
    AdminTestHelpers::actingAdmin(['users.view', 'users.update', 'customers.view']);

    $target = User::factory()->create();
    LoginLog::record('admin', $target, $target->email, LoginResult::Success);

    Livewire::test(
        \App\Filament\Admin\Resources\UserResource\RelationManagers\LoginLogsRelationManager::class,
        ['ownerRecord' => $target, 'pageClass' => EditUser::class],
    )->assertCanSeeTableRecords($target->loginLogs()->get());
});
