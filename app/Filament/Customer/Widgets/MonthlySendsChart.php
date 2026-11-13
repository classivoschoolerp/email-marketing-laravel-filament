<?php

declare(strict_types=1);

namespace App\Filament\Customer\Widgets;

use App\Models\Customer;
use App\Services\Quota\QuotaUsageService;
use Filament\Widgets\ChartWidget;

/**
 * Historical usage chart (spec §2.15.5 F-2.15.5-006, WP-18): delivered
 * emails per calendar month over the last 12 months, from the
 * campaign_stats_daily rollup. Rendered as a header widget on the Usage &
 * quotas page — deliberately NOT part of the dashboard line-up.
 */
class MonthlySendsChart extends ChartWidget
{
    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('dashboards.usage.history_heading');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        /** @var Customer $customer */
        $customer = auth('customer')->user();

        $buckets = app(QuotaUsageService::class)->monthlySendSeries($customer);

        return [
            'datasets' => [
                [
                    'label' => __('dashboards.usage.history_series'),
                    'data' => $buckets->values()->all(),
                    'backgroundColor' => 'rgba(5, 150, 105, 0.55)',
                    'borderColor' => '#059669',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $buckets->keys()->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
