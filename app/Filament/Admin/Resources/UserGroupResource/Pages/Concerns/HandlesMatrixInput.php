<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserGroupResource\Pages\Concerns;

use App\Models\UserGroup;
use App\Support\Admin\AdminModules;
use App\Support\Roles\RoleChecker;

/**
 * Maps the §3.3 matrix checkboxes (per-module view/create/update/delete +
 * extra delegation permissions) to the group role's spatie permission set.
 */
trait HandlesMatrixInput
{
    /** @var list<string> */
    protected array $pendingMatrixPermissions = [];

    /** @param array<string, mixed> $data */
    protected function stashMatrixInput(array $data): array
    {
        $names = [];

        foreach (AdminModules::MODULES as $module) {
            foreach ((array) ($data['matrix'][$module] ?? []) as $action) {
                if (in_array((string) $action, AdminModules::ACTIONS, true)) {
                    $names[] = AdminModules::permission($module, (string) $action);
                }
            }
        }

        foreach ((array) ($data['extra_permissions'] ?? []) as $extra) {
            if (in_array((string) $extra, AdminModules::EXTRA_PERMISSIONS, true)) {
                $names[] = (string) $extra;
            }
        }

        $this->pendingMatrixPermissions = $names;

        unset($data['matrix'], $data['extra_permissions']);

        return $data;
    }

    protected function applyMatrixInput(UserGroup $group): void
    {
        $group->syncMatrix($this->pendingMatrixPermissions);

        app(RoleChecker::class)->flush();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillMatrixInput(UserGroup $group, array $data): array
    {
        $current = $group->matrixPermissions();

        $data['matrix'] = [];

        foreach (AdminModules::MODULES as $module) {
            $data['matrix'][$module] = array_values(array_filter(
                AdminModules::ACTIONS,
                fn (string $action): bool => in_array(AdminModules::permission($module, $action), $current, true),
            ));
        }

        $data['extra_permissions'] = array_values(array_intersect($current, AdminModules::EXTRA_PERMISSIONS));

        return $data;
    }
}
