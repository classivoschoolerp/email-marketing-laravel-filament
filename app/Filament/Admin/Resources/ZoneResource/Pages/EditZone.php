<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ZoneResource\Pages;

use App\Filament\Admin\Resources\ZoneResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditZone extends EditRecord
{
    protected static string $resource = ZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
