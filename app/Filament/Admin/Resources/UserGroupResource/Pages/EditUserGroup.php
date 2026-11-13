<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserGroupResource\Pages;

use App\Filament\Admin\Resources\UserGroupResource;
use App\Filament\Admin\Resources\UserGroupResource\Pages\Concerns\HandlesMatrixInput;
use App\Models\UserGroup;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUserGroup extends EditRecord
{
    use HandlesMatrixInput;

    protected static string $resource = UserGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var UserGroup $group */
        $group = $this->getRecord();

        return $this->fillMatrixInput($group, $data);
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->stashMatrixInput($data);
    }

    protected function afterSave(): void
    {
        /** @var UserGroup $group */
        $group = $this->getRecord();

        $this->applyMatrixInput($group);
    }
}
