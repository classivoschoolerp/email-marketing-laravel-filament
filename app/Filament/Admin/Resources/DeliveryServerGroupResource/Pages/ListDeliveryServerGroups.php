<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeliveryServerGroupResource\Pages;

use App\Filament\Admin\Resources\DeliveryServerGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryServerGroups extends ListRecords
{
    protected static string $resource = DeliveryServerGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
