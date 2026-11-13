<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\TrackingDomainResource\Pages;

use App\Filament\Customer\Resources\TrackingDomainResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTrackingDomain extends EditRecord
{
    protected static string $resource = TrackingDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
