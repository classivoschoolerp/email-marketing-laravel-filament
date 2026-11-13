<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RoleResource\Pages;

use App\Enums\RoleScope;
use App\Filament\Admin\Resources\RoleResource;
use App\Models\Role;
use App\Support\Roles\RoleChecker;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** @var list<string> */
    private array $pendingPermissions = [];

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingPermissions = array_values((array) ($data['permission_names'] ?? []));

        unset($data['permission_names']);

        // Operator-created roles are always global admin roles (D-06);
        // system roles are seeded and locked.
        $data['guard_name'] = 'admin';
        $data['scope'] = RoleScope::Global;
        $data['locked'] = false;

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        // Role::create runs the per-tenant duplicate check (D-17).
        return Role::create($data);
    }

    protected function afterCreate(): void
    {
        /** @var Role $role */
        $role = $this->record;

        $role->syncPermissions(array_map(
            fn (string $name): Permission => Permission::findOrCreate($name, 'admin'),
            $this->pendingPermissions,
        ));

        app(RoleChecker::class)->flush();
    }
}
