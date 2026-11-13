<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Resources\UserResource\Pages\Concerns\SyncsUserAccess;
use App\Models\User;
use App\Policies\UserPolicy;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use SyncsUserAccess;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                // F-3.2-004: never yourself, never the last Super Admin.
                ->visible(fn (): bool => UserPolicy::deletionAllowed(auth('admin')->user(), $this->getRecord())
                    && auth('admin')->user()->can('delete', $this->getRecord())),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();

        $data['assigned_roles'] = $user->roles()
            // group-backing roles are managed via the group select
            ->where('roles.name', 'not like', 'user-group:%')
            ->pluck('roles.id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $impersonatePermission = (string) config('emp.impersonation.permission', 'customers.impersonate');
        $direct = $user->permissions()->pluck('name')->map(fn ($n): string => (string) $n)->all();

        $data['can_impersonate'] = in_array($impersonatePermission, $direct, true);
        $data['direct_permissions'] = array_values(array_diff($direct, [$impersonatePermission]));

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->stashAccessInput($data);
    }

    protected function afterSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();

        $this->applyAccessInput($user->refresh());
    }
}
