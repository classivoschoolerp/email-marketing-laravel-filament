<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Services\Quota\QuotaService;
use Illuminate\Validation\ValidationException;

/**
 * max_templates quota guard (spec §0.3 quota matrix). Enforced on every path
 * that creates a customer-owned template: create page, duplicate, ZIP/URL
 * import and gallery copy. Default is unlimited (no config row) — per-group
 * limits come from customer_group_limits key "max_templates".
 */
class TemplateQuota
{
    public const KEY = 'max_templates';

    public function __construct(private readonly QuotaService $quotas) {}

    public function currentUsage(Customer $customer): int
    {
        return EmailTemplate::withoutTenancy()
            ->where('customer_id', $customer->id)
            ->count();
    }

    public function canCreate(Customer $customer, int $adding = 1): bool
    {
        return $this->quotas->withinLimit($customer, self::KEY, $this->currentUsage($customer), $adding);
    }

    /** @throws ValidationException */
    public function assertCanCreate(Customer $customer, int $adding = 1): void
    {
        if (! $this->canCreate($customer, $adding)) {
            throw ValidationException::withMessages([
                'name' => __('templates.errors.quota_exceeded'),
            ]);
        }
    }
}
