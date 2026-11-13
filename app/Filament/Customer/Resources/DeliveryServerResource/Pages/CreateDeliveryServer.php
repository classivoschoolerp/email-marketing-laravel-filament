<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\DeliveryServerResource\Pages;

use App\Enums\DeliveryServerStatus;
use App\Filament\Customer\Resources\DeliveryServerResource;
use App\Models\Customer;
use App\Services\Sending\DeliveryServerAssignments;
use App\Services\Sending\DeliveryServerQuota;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create page (spec §2.9.2). Policy gates the page (permission + count
 * quota); the type whitelist chain is re-asserted here where the chosen
 * driver key is known (DeliveryServerQuota, D-21/D-42). New servers start
 * inactive until validated/enabled (F-2.9.2-041/042).
 *
 * F-2.9.2-038: the list/campaign assignment is validated BEFORE the row is
 * created (a forged id must not leave a half-built server behind) and written
 * once the record exists.
 */
class CreateDeliveryServer extends CreateRecord
{
    protected static string $resource = DeliveryServerResource::class;

    /** @var array{list: list<int>, campaign: list<int>} */
    protected array $assignment = DeliveryServerAssignments::NONE;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $customer = auth('customer')->user();

        if ($customer instanceof Customer) {
            app(DeliveryServerQuota::class)->assertCanCreate($customer, (string) ($data['type'] ?? ''));
        }

        $this->assignment = app(DeliveryServerAssignments::class)->validate(
            $customer instanceof Customer ? (int) $customer->id : null,
            (array) ($data['assigned_list_ids'] ?? []),
            (array) ($data['assigned_campaign_ids'] ?? []),
        );

        unset($data['assigned_list_ids'], $data['assigned_campaign_ids']);

        $data['status'] = DeliveryServerStatus::Inactive->value;

        return $data;
    }

    protected function afterCreate(): void
    {
        app(DeliveryServerAssignments::class)->sync(
            $this->record,
            $this->assignment['list'],
            $this->assignment['campaign'],
        );
    }
}
