<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\DeliveryServerResource\Pages;

use App\Filament\Customer\Resources\DeliveryServerResource;
use App\Services\Sending\DeliveryServerAssignments;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit page (spec §2.9.2). DeliveryServerPolicy::update denies system rows
 * and admin-locked rows (F-2.9.2-037/040) — Filament 403s before rendering.
 * The type select is disabled on edit, so the driver key never changes.
 *
 * F-2.9.2-038: the list/campaign assignment lives in its own table, so it is
 * filled into the form by hand and re-synced after save (cross-tenant ids are
 * refused by DeliveryServerAssignments::sync()).
 */
class EditDeliveryServer extends EditRecord
{
    protected static string $resource = DeliveryServerResource::class;

    /** @var array{list: list<int>, campaign: list<int>} */
    protected array $assignment = DeliveryServerAssignments::NONE;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $assigned = app(DeliveryServerAssignments::class)->forServer($this->getRecord());

        $data['assigned_list_ids'] = $assigned['list'];
        $data['assigned_campaign_ids'] = $assigned['campaign'];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->assignment = app(DeliveryServerAssignments::class)->validate(
            $this->getRecord()->customer_id === null ? null : (int) $this->getRecord()->customer_id,
            (array) ($data['assigned_list_ids'] ?? []),
            (array) ($data['assigned_campaign_ids'] ?? []),
        );

        unset($data['assigned_list_ids'], $data['assigned_campaign_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        app(DeliveryServerAssignments::class)->sync(
            $this->getRecord(),
            $this->assignment['list'],
            $this->assignment['campaign'],
        );
    }
}
