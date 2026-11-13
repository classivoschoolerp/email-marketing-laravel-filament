<?php

declare(strict_types=1);

namespace App\Filament\Customer\Widgets;

use App\Models\Customer;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * Sends / opens / clicks over the last 30 days (spec §2.1 F-2.1-004, WP-18),
 * read from the campaign_stats_daily rollup restricted to the tenant's
 * campaigns. Empty days render as zero so the axis is continuous.
 */
class EngagementChart extends ChartWidget
{
    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '260px';

    protected static ?int $sort = 40;

    public function getHeading(): string
    {
        return __('dashboards.customer.engagement.heading');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = $this->series();

        $dataset = fn (string $key, string $label, string $color): array => [
            'label' => $label,
            'data' => array_column($series, $key),
            'borderColor' => $color,
            'backgroundColor' => $color,
            'borderWidth' => 2,
            'pointRadius' => 0,
            'pointHitRadius' => 12,
            'fill' => false,
            'tension' => 0.15,
        ];

        return [
            'datasets' => [
                $dataset('sent', __('dashboards.customer.engagement.sends'), '#059669'),
                $dataset('opens', __('dashboards.customer.engagement.opens'), '#2a78d6'),
                $dataset('clicks', __('dashboards.customer.engagement.clicks'), '#d97706'),
            ],
            'labels' => array_keys($series),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }

    /**
     * Day-keyed sends/opens/clicks for the last 30 days, empty days filled.
     *
     * @return array<string, array{sent: int, opens: int, clicks: int}>
     */
    public function series(): array
    {
        /** @var Customer $customer */
        $customer = auth('customer')->user();

        $start = now()->startOfDay()->subDays(29);

        $rows = DB::table('campaign_stats_daily')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_stats_daily.campaign_id')
            ->where('campaigns.customer_id', $customer->id)
            ->where('day', '>=', $start->format('Y-m-d'))
            ->selectRaw('date(day) as bucket, sum(sent) as sent, sum(opens) as opens, sum(clicks) as clicks')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $series = [];

        for ($i = 0; $i < 30; $i++) {
            $day = $start->copy()->addDays($i)->format('Y-m-d');
            $row = $rows->get($day);

            $series[$day] = [
                'sent' => (int) ($row->sent ?? 0),
                'opens' => (int) ($row->opens ?? 0),
                'clicks' => (int) ($row->clicks ?? 0),
            ];
        }

        return $series;
    }
}
