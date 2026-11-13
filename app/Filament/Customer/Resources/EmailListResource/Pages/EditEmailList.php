<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Pages;

use App\Filament\Customer\Resources\EmailListResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit list (spec §2.2.2) — tabbed form defined on the resource.
 */
class EditEmailList extends EditRecord
{
    protected static string $resource = EmailListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('overview')
                ->label(__('lists.actions.overview'))
                ->url(fn (): string => EmailListResource::getUrl('overview', ['record' => $this->getRecord()])),
            Actions\Action::make('fields')
                ->label(__('lists.actions.fields'))
                ->url(fn (): string => EmailListResource::getUrl('fields', ['record' => $this->getRecord()])),
            Actions\Action::make('segments')
                ->label(__('lists.actions.segments'))
                ->url(fn (): string => EmailListResource::getUrl('segments', ['record' => $this->getRecord()])),
        ];
    }
}
