<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeliveryServerResource\Pages;

use App\Filament\Admin\Resources\DeliveryServerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Admin edit page (spec §3.13.1) — admins may edit ANY row, including locked
 * and system rows (the locked flag restricts customers only). Saving with a
 * sending_domain_id keeps the server's DKIM material in sync with the domain
 * (F-2.9.7-007).
 */
class EditDeliveryServer extends EditRecord
{
    protected static string $resource = DeliveryServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['customer_groups'] ?? null) === []) {
            $data['customer_groups'] = null;
        }

        if (! blank($data['customer_id'] ?? null)) {
            $data['customer_groups'] = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        DeliveryServerResource::syncDkimFromDomain($this->record->refresh());
    }
}
