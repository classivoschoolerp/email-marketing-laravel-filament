<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeliveryServerResource\Pages;

use App\Filament\Admin\Resources\DeliveryServerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * Admin delivery servers index (spec §3.13.1 / §2.9.1 columns, WP-15).
 */
class ListDeliveryServers extends ListRecords
{
    protected static string $resource = DeliveryServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
