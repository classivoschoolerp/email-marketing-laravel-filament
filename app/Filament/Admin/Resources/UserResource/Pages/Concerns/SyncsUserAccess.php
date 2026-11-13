<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\Pages\Concerns;

use App\Models\User;
use App\Support\Roles\RoleChecker;
use Spatie\Permission\Models\Permission;

/**
 * Shared role/permission assignment for the user form (spec §3.2
 * F-3.2-003/005): syncs the checked roles (plus the user group's backing
 * role), the direct granular permissions and the impersonate-customer flag.
 */
trait SyncsUserAccess
{
    /** @var list<int|string> */
    protected array $pendingRoleIds = [];

    /** @var list<string> */
    protected array $pendingPermissions = [];

    protected bool $pendingImpersonate = false;

    /** @param array<string, mixed> $data */
    protected function stashAccessInput(array $data): array
    {
        $this->pendingRoleIds = array_values((array) ($data['assigned_roles'] ?? []));
        $this->pendingPermissions = array_values((array) ($data['direct_permissions'] ?? []));
        $this->pendingImpersonate = (bool) ($data['can_impersonate'] ?? false);

        unset($data['assigned_roles'], $data['direct_permissions'], $data['can_impersonate']);

        return $data;
    }

    protected function applyAccessInput(User $user): void
    {
        $roleIds = array_map(intval(...), $this->pendingRoleIds);

        // The user group's backing role is managed through user_group_id
        // (§3.3 F-3.3-003) and always stays attached alongside manual roles.
        $groupRole = $user->group?->ensureRole();

        if ($groupRole !== null) {
            $roleIds[] = (int) $groupRole->getKey();
        }

        $user->roles()->sync(array_values(array_unique($roleIds)));

        $permissions = $this->pendingPermissions;

        if ($this->pendingImpersonate) {
            $permissions[] = (string) config('emp.impersonation.permission', 'customers.impersonate');
        }

        $user->syncPermissions(array_map(
            fn (string $name): Permission => Permission::findOrCreate($name, 'admin'),
            array_values(array_unique($permissions)),
        ));

        app(RoleChecker::class)->flush();
    }
}
