<?php

declare(strict_types=1);

namespace App\Filament\Customer\Pages;

use App\Enums\SubscriptionStatus;
use App\Filament\Customer\Widgets\MonthlySendsChart;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\Billing\PlanMetering;
use App\Services\Quota\QuotaUsageService;
use App\Support\Money\MoneyFormatter;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;

/**
 * Usage & quotas page (spec §2.15.5, WP-18): current plan/group summary,
 * sending quota bars with reset times, subscriber count vs limit, object
 * counts vs limits, storage used, the 12-month historical usage chart and
 * an upgrade CTA when near any limit. Billing runs in a parallel work
 * package —
 * the CTA links to the billing plans route only when it exists
 * (Route::has), otherwise it renders the plain contact-your-admin text.
 */
class UsageAndQuotas extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $slug = 'usage';

    protected static ?int $navigationSort = 92;

    protected static string $view = 'filament.customer.pages.usage-and-quotas';

    public static function getNavigationGroup(): ?string
    {
        return __('auth-module.profile.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('dashboards.usage.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('dashboards.usage.title');
    }

    /** @return array<int, class-string> */
    protected function getHeaderWidgets(): array
    {
        return [MonthlySendsChart::class];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $customer = $this->customer();
        $service = app(QuotaUsageService::class);

        return [
            'customer' => $customer,
            'groupName' => $customer->group?->name,
            'sending' => $service->sendingUsage($customer),
            'objects' => $service->objectUsage($customer),
            'storage' => $service->formatBytes($service->storageBytes($customer)),
            'nearLimit' => $service->nearAnyLimit($customer),
            'upgradeUrl' => Route::has('billing.plans') ? route('billing.plans') : null,
            // F-3.26.1-009 (WP-46): what the current, still-open billing
            // window would cost in overage if it closed right now.
            'overage' => $this->projectedOverage($customer),
        ];
    }

    /**
     * F-3.26.1-009 — projected overage for the tenant's current plan, or null
     * when they are not on an overage plan. Read-only: it bills nothing and
     * never advances the D-93 usage window.
     *
     * @return array<string, mixed>|null
     */
    public function projectedOverage(Customer $customer): ?array
    {
        if (! class_exists(PlanMetering::class)) {
            return null;
        }

        $subscription = Subscription::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trial->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->with('plan')
            ->latest('id')
            ->first();

        if ($subscription === null) {
            return null;
        }

        $projection = app(PlanMetering::class)->projectedOverage($subscription);

        if ($projection === null) {
            return null;
        }

        $formatter = app(MoneyFormatter::class);

        return $projection + [
            'amount_formatted' => $formatter->formatCode($projection['amount'], $projection['currency']),
            'unit_price_formatted' => $formatter->formatCode($projection['unit_price'], $projection['currency']),
        ];
    }

    public function customer(): Customer
    {
        /** @var Customer $customer */
        $customer = auth('customer')->user();

        return $customer;
    }
}
