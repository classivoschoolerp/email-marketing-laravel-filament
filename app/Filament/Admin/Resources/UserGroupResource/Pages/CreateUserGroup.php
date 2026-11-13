<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserGroupResource\Pages;

use App\Filament\Admin\Resources\UserGroupResource;
use App\Filament\Admin\Resources\UserGroupResource\Pages\Concerns\HandlesMatrixInput;
use App\Models\UserGroup;
use Filament\Resources\Pages\CreateRecord;

class CreateUserGroup extends CreateRecord
{
    use HandlesMatrixInput;

    protected static string $resource = UserGroupResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->stashMatrixInput($data);
    }

    protected function afterCreate(): void
    {
        /** @var UserGroup $group */
        $group = $this->record;

        $this->applyMatrixInput($group);
    }
}
