<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FblServerResource\Pages;

use App\Filament\Admin\Resources\FblServerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFblServers extends ListRecords
{
    protected static string $resource = FblServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
