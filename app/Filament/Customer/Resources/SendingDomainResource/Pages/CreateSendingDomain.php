<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SendingDomainResource\Pages;

use App\Filament\Customer\Resources\SendingDomainResource;
use App\Models\Customer;
use App\Models\SendingDomain;
use App\Policies\SendingDomainPolicy;
use App\Services\Dkim\GenerateDkimKeypair;
use App\Services\Quota\QuotaService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

/**
 * Customer create (spec §2.9.7 F-2.9.7-001): keypair generated on save; the
 * domains.sending.add / max_sending_domains gate is re-asserted here in
 * addition to the policy (belt and braces on the write path).
 */
class CreateSendingDomain extends CreateRecord
{
    protected static string $resource = SendingDomainResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $customer = auth('customer')->user();

        if ($customer instanceof Customer) {
            $quotas = app(QuotaService::class);

            $allowed = $quotas->allows($customer, SendingDomainPolicy::ADD_PERMISSION)
                && $quotas->withinLimit(
                    $customer,
                    SendingDomainPolicy::COUNT_KEY,
                    SendingDomain::withoutTenancy()->where('customer_id', $customer->id)->count(),
                );

            if (! $allowed) {
                throw ValidationException::withMessages([
                    'name' => __('infra.errors.cannot_create_domain'),
                ]);
            }
        }

        $pair = app(GenerateDkimKeypair::class)();

        $data['dkim_private_key'] = $pair['private_key'];
        $data['dkim_public_key'] = $pair['public_key'];

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return SendingDomainResource::getUrl('view', ['record' => $this->record]);
    }
}
