<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\FormResource\Pages;

use App\Filament\Customer\Resources\FormResource;
use App\Services\Forms\DisplayRules;
use App\Services\Forms\FormSteps;
use App\Services\Forms\FormStyles;
use App\Services\Forms\FrequencyRules;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit a form (§2.7). The JSON rule documents are filled with their
 * defaults on load — an older form saved before a rule existed shows the
 * default rather than an empty control.
 */
class EditForm extends EditRecord
{
    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('fields')
                ->label(__('forms.actions.fields'))
                ->icon('heroicon-m-list-bullet')
                ->color('gray')
                ->url(fn (): string => FormResource::getUrl('fields', ['record' => $this->getRecord()])),
            Actions\Action::make('variants')
                ->label(__('forms.actions.ab'))
                ->icon('heroicon-m-beaker')
                ->color('gray')
                ->url(fn (): string => FormResource::getUrl('variants', ['record' => $this->getRecord()])),
            Actions\Action::make('embed')
                ->label(__('forms.actions.embed'))
                ->icon('heroicon-m-code-bracket')
                ->color('gray')
                ->url(fn (): string => FormResource::getUrl('embed', ['record' => $this->getRecord()])),
            Actions\DeleteAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['display_rules'] = array_merge(DisplayRules::defaults(), (array) ($data['display_rules'] ?? []));
        $data['frequency_rules'] = array_merge(FrequencyRules::defaults(), (array) ($data['frequency_rules'] ?? []));
        $data['styles'] = array_merge(FormStyles::defaults(), (array) ($data['styles'] ?? []));
        $data['steps'] = FormSteps::normalize((array) ($data['steps'] ?? []));

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return FormResource::normalizeJsonDocuments($data);
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
