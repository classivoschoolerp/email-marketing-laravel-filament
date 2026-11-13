<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SendingDomainResource\Pages;

use App\Filament\Customer\Resources\SendingDomainResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSendingDomain extends EditRecord
{
    protected static string $resource = SendingDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
