<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SurveyResource\Pages;

use App\Filament\Customer\Resources\SurveyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit a survey (F-2.8-002 / F-2.8-004 / F-2.8-009).
 */
class EditSurvey extends EditRecord
{
    protected static string $resource = SurveyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('questions')
                ->label(__('surveys.actions.questions'))
                ->icon('heroicon-m-queue-list')
                ->color('gray')
                ->url(fn (): string => SurveyResource::getUrl('questions', ['record' => $this->getRecord()])),
            Actions\Action::make('public')
                ->label(__('surveys.actions.open_public'))
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => route('surveys.public.start', ['uid' => $this->getRecord()->uid]))
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
