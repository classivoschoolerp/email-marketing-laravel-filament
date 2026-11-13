<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SubscriberResource\Pages;

use App\Filament\Customer\Resources\SubscriberResource;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit subscriber (spec §2.2.6): same dynamic form as create; custom field
 * values load from and persist to the EAV store via FieldValueService.
 */
class EditSubscriber extends EditRecord
{
    protected static string $resource = SubscriberResource::class;

    /** @var array<int|string, mixed> */
    protected array $fieldValues = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()
                ->label(__('subscribers.actions.view_profile')),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Subscriber $subscriber */
        $subscriber = $this->getRecord();

        $service = app(FieldValueService::class);
        $values = [];

        foreach ($subscriber->list?->fields ?? [] as $field) {
            $values[$field->id] = $service->formValue($field, $service->raw($subscriber, $field));
        }

        $data['field_values'] = $values;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->fieldValues = (array) ($data['field_values'] ?? []);

        unset($data['field_values'], $data['skip_confirmation'], $data['merge_duplicate'], $data['list_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Subscriber $subscriber */
        $subscriber = $this->getRecord();

        $service = app(FieldValueService::class);

        foreach ($this->fieldValues as $fieldId => $value) {
            $field = ListField::query()
                ->where('list_id', $subscriber->list_id)
                ->find((int) $fieldId);

            if ($field instanceof ListField) {
                $service->set($subscriber, $field, $value);
            }
        }
    }
}
