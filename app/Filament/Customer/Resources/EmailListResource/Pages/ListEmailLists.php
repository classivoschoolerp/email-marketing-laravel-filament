<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Pages;

use App\Filament\Customer\Resources\EmailListResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * Lists index (spec §2.2.1). "Create list" opens the wizard (F-2.2.1-006);
 * the button disappears when the max_lists quota is reached (EmailListPolicy).
 */
class ListEmailLists extends ListRecords
{
    protected static string $resource = EmailListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('lists.actions.create')),
        ];
    }
}
