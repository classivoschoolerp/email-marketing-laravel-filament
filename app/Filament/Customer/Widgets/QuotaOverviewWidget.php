<?php

declare(strict_types=1);

namespace App\Filament\Customer\Widgets;

use App\Models\Customer;
use App\Services\Quota\QuotaUsageService;
use Filament\Widgets\Widget;

/**
 * Quota widgets (spec §2.1 F-2.1-002, WP-18): sending quota used/remaining
 * for the hourly/daily/monthly windows with reset countdowns, the subscriber
 * quota and storage used — all via QuotaUsageService (per-customer override >
 * group matrix > config default; storage from the customer's real
 * directories).
 */
class QuotaOverviewWidget extends Widget
{
    protected static string $view = 'filament.customer.widgets.quota-overview';

    protected static bool $isLazy = false;

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $customer = $this->customer();
        $service = app(QuotaUsageService::class);

        return [
            'sending' => $service->sendingUsage($customer),
            'subscribers' => $service->objectUsage($customer)['subscribers'],
            'storage' => $service->formatBytes($service->storageBytes($customer)),
        ];
    }

    private function customer(): Customer
    {
        /** @var Customer $customer */
        $customer = auth('customer')->user();

        return $customer;
    }
}
