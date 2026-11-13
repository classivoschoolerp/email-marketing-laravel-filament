<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RoleResource\Pages;

use App\Filament\Admin\Resources\RoleResource;
use App\Models\Role;
use App\Support\Roles\RoleChecker;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Permission;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @var list<string> */
    private array $pendingPermissions = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $role */
        $role = $this->getRecord();

        $data['permission_names'] = $role->permissions()
            ->pluck('name')
            ->map(fn ($n): string => (string) $n)
            ->all();

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingPermissions = array_values((array) ($data['permission_names'] ?? []));

        unset($data['permission_names']);

        // Guard/scope/lock state is never editable from the form.
        unset($data['guard_name'], $data['scope'], $data['locked']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Role $role */
        $role = $this->getRecord();

        $role->syncPermissions(array_map(
            fn (string $name): Permission => Permission::findOrCreate($name, 'admin'),
            $this->pendingPermissions,
        ));

        app(RoleChecker::class)->flush();
    }
}
