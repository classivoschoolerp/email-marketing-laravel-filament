<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\TrackingDomainResource\Pages;

use App\Filament\Customer\Resources\TrackingDomainResource;
use App\Models\Customer;
use App\Models\TrackingDomain;
use App\Policies\TrackingDomainPolicy;
use App\Services\Quota\QuotaService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

/**
 * Customer create (spec §2.9.8 F-2.9.8-001) — the domains.tracking.add /
 * max_tracking_domains gate is re-asserted on the write path.
 */
class CreateTrackingDomain extends CreateRecord
{
    protected static string $resource = TrackingDomainResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $customer = auth('customer')->user();

        if ($customer instanceof Customer) {
            $quotas = app(QuotaService::class);

            $allowed = $quotas->allows($customer, TrackingDomainPolicy::ADD_PERMISSION)
                && $quotas->withinLimit(
                    $customer,
                    TrackingDomainPolicy::COUNT_KEY,
                    TrackingDomain::withoutTenancy()->where('customer_id', $customer->id)->count(),
                );

            if (! $allowed) {
                throw ValidationException::withMessages([
                    'name' => __('infra.errors.cannot_create_domain'),
                ]);
            }
        }

        return $data;
    }
}
