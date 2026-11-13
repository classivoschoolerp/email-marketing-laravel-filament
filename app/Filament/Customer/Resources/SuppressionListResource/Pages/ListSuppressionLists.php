<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SuppressionListResource\Pages;

use App\Filament\Customer\Resources\SuppressionListResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/** §2.9.11 suppression lists index (WP-16). */
class ListSuppressionLists extends ListRecords
{
    protected static string $resource = SuppressionListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
