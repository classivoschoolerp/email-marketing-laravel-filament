<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SubscriberResource\Pages;

use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\SubscriberResource;
use App\Models\Customer;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Add subscriber (spec §2.2.6): dynamic custom fields by type, status
 * selector, skip/force confirmation, duplicate detection against
 * (list_id, email) with a merge option.
 */
class CreateSubscriber extends CreateRecord
{
    protected static string $resource = SubscriberResource::class;

    /** @var array<int|string, mixed> */
    protected array $fieldValues = [];

    protected bool $merged = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Pin the tenant from the authenticated customer (docs/ARCHITECTURE.md rule 3).
        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $data['customer_id'] = $user->id;
        }

        // Skip confirmation → store confirmed immediately (F-2.2.6-003).
        if (($data['skip_confirmation'] ?? false) === true
            && ($data['status'] ?? null) === SubscriberStatus::Unconfirmed->value) {
            $data['status'] = SubscriberStatus::Confirmed->value;
        }

        $this->fieldValues = (array) ($data['field_values'] ?? []);

        unset($data['field_values'], $data['skip_confirmation'], $data['merge_duplicate']);

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        // Duplicate merge (F-2.2.6-004): the duplicate rule passed because
        // merge_duplicate was on — update the existing row instead of failing.
        $existing = Subscriber::query()
            ->where('list_id', $data['list_id'] ?? null)
            ->where('email', mb_strtolower(trim((string) ($data['email'] ?? ''))))
            ->first();

        if ($existing !== null) {
            $this->merged = true;

            $existing->fill([
                'status' => $data['status'] ?? $existing->status,
                'source' => $data['source'] ?? $existing->source,
            ]);
            $existing->save();

            return $existing;
        }

        return parent::handleRecordCreation($data);
    }

    protected function afterCreate(): void
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

        if ($this->merged) {
            Notification::make()
                ->title(__('subscribers.form.merged'))
                ->success()
                ->send();
        }
    }
}
