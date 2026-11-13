<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\TrackingDomainResource\Pages;

use App\Filament\Customer\Resources\TrackingDomainResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTrackingDomains extends ListRecords
{
    protected static string $resource = TrackingDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(), // hidden when TrackingDomainPolicy::create denies
        ];
    }
}
