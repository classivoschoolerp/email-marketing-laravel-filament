<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Resources\UserResource\Pages\Concerns\SyncsUserAccess;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use SyncsUserAccess;

    protected static string $resource = UserResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->stashAccessInput($data);
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->record;

        $this->applyAccessInput($user);
    }
}
