<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Enums\CampaignType;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Quota\QuotaService;
use App\Services\Settings\SettingsReader;
use Illuminate\Validation\ValidationException;

/**
 * Campaign quota + type gates (spec §0.3/§3.5). Reads the same
 * customer-group matrix rows as every other module: limit `max_campaigns`
 * and the campaigns.regular/autoresponder/recurring type permissions
 * (CustomerGroupMatrix), with per-customer overrides via QuotaService.
 */
class CampaignQuota
{
    public const KEY = 'max_campaigns';

    public function __construct(private readonly QuotaService $quotas) {}

    public function currentUsage(Customer $customer): int
    {
        return Campaign::withoutTenancy()->where('customer_id', $customer->id)->count();
    }

    public function canCreate(Customer $customer, int $adding = 1): bool
    {
        return $this->quotas->withinLimit($customer, self::KEY, $this->currentUsage($customer), $adding);
    }

    /**
     * @throws ValidationException when the max_campaigns quota is exhausted
     */
    public function assertCanCreate(Customer $customer, int $adding = 1): void
    {
        if (! $this->canCreate($customer, $adding)) {
            throw ValidationException::withMessages([
                'name' => __('campaigns.errors.quota_exceeded'),
            ]);
        }
    }

    /** Is the campaign type enabled for this customer's group (§3.5)? */
    public function typeAllowed(Customer $customer, CampaignType $type): bool
    {
        return $this->quotas->allows($customer, $type->permissionKey());
    }

    /** @return list<CampaignType> */
    public function allowedTypes(Customer $customer): array
    {
        return array_values(array_filter(
            CampaignType::cases(),
            fn (CampaignType $type): bool => $this->typeAllowed($customer, $type),
        ));
    }

    /**
     * @throws ValidationException when the type is not allowed for the group
     */
    public function assertTypeAllowed(Customer $customer, CampaignType $type): void
    {
        if (! $this->typeAllowed($customer, $type)) {
            throw ValidationException::withMessages([
                'type' => __('campaigns.errors.type_not_allowed', ['type' => $type->label()]),
            ]);
        }
    }

    /**
     * Effective recipients cap: the stricter of the §3.25.3 global setting
     * and the group/customer max_campaign_recipients limit. Null = no cap
     * (D-23: 0 = unlimited for the setting).
     */
    public function maxRecipients(Customer $customer): ?int
    {
        $setting = SettingsReader::campaign()->maxCampaignRecipients();
        $limit = $this->quotas->limitFor($customer, 'max_campaign_recipients');

        $caps = array_values(array_filter([
            $setting > 0 ? $setting : null,
            $limit,
        ], fn (?int $value): bool => $value !== null));

        return $caps === [] ? null : min($caps);
    }
}
