<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SuppressionListResource\Pages;

use App\Filament\Customer\Resources\SuppressionListResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/** §2.9.11 rename / describe a suppression list. */
class EditSuppressionList extends EditRecord
{
    protected static string $resource = SuppressionListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
