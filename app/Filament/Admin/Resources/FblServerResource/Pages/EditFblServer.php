<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FblServerResource\Pages;

use App\Filament\Admin\Resources\FblServerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFblServer extends EditRecord
{
    protected static string $resource = FblServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
