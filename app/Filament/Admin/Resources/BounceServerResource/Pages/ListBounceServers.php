<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BounceServerResource\Pages;

use App\Filament\Admin\Resources\BounceServerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBounceServers extends ListRecords
{
    protected static string $resource = BounceServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
