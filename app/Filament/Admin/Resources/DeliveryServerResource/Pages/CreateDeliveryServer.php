<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeliveryServerResource\Pages;

use App\Filament\Admin\Resources\DeliveryServerResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Admin create page (spec §3.13.1). Admin rows bypass the customer quota
 * chain (operators provision infrastructure); empty group assignment is
 * normalised to NULL = all groups (D-53). Linking a sending domain copies
 * its DKIM material onto the server (F-2.9.7-007).
 */
class CreateDeliveryServer extends CreateRecord
{
    protected static string $resource = DeliveryServerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['customer_groups'] ?? null) === []) {
            $data['customer_groups'] = null;
        }

        if (! blank($data['customer_id'] ?? null)) {
            // Direct-assigned rows are customer rows; group assignment is
            // only meaningful on system rows.
            $data['customer_groups'] = null;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        DeliveryServerResource::syncDkimFromDomain($this->record->refresh());
    }
}
