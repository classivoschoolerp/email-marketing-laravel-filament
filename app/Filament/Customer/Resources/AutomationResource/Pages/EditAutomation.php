<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\AutomationResource\Pages;

use App\Filament\Customer\Resources\AutomationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/** Settings page (name + re-entry rules, F-2.4.2-043). */
class EditAutomation extends EditRecord
{
    protected static string $resource = AutomationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('builder')
                ->label(__('automations.actions.builder'))
                ->icon('heroicon-m-pencil-square')
                ->url(fn (): string => AutomationResource::getUrl('builder', ['record' => $this->record])),

            Actions\Action::make('report')
                ->label(__('automations.actions.report'))
                ->icon('heroicon-m-chart-bar')
                ->color('gray')
                ->url(fn (): string => AutomationResource::getUrl('report', ['record' => $this->record])),

            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return AutomationResource::getUrl('index');
    }
}
