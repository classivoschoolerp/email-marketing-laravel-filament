<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SendingDomainResource\Pages;

use App\Filament\Customer\Resources\SendingDomainResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSendingDomains extends ListRecords
{
    protected static string $resource = SendingDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(), // hidden when SendingDomainPolicy::create denies
        ];
    }
}
