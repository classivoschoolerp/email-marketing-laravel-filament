<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * System-wide sends / opens / clicks / bounces over the last 30 days (spec
 * §3.1 F-3.1-002, WP-18). Sends/opens/clicks come from the
 * campaign_stats_daily rollup (all tenants); bounces from
 * campaign_bounce_logs grouped by day — the rollup carries no bounce column.
 */
class SystemEngagementChart extends ChartWidget
{
    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '280px';

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('dashboards.admin.engagement.heading');
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
                $dataset('sent', __('dashboards.admin.engagement.sends'), '#4f46e5'),
                $dataset('opens', __('dashboards.admin.engagement.opens'), '#2a78d6'),
                $dataset('clicks', __('dashboards.admin.engagement.clicks'), '#d97706'),
                $dataset('bounces', __('dashboards.admin.engagement.bounces'), '#dc2626'),
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
     * Day-keyed system-wide totals for the last 30 days, empty days filled.
     *
     * @return array<string, array{sent: int, opens: int, clicks: int, bounces: int}>
     */
    public function series(): array
    {
        $start = now()->startOfDay()->subDays(29);

        $stats = DB::table('campaign_stats_daily')
            ->where('day', '>=', $start->format('Y-m-d'))
            ->selectRaw('date(day) as bucket, sum(sent) as sent, sum(opens) as opens, sum(clicks) as clicks')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $bounces = DB::table('campaign_bounce_logs')
            ->where('created_at', '>=', $start)
            ->selectRaw('date(created_at) as bucket, count(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $series = [];

        for ($i = 0; $i < 30; $i++) {
            $day = $start->copy()->addDays($i)->format('Y-m-d');
            $row = $stats->get($day);

            $series[$day] = [
                'sent' => (int) ($row->sent ?? 0),
                'opens' => (int) ($row->opens ?? 0),
                'clicks' => (int) ($row->clicks ?? 0),
                'bounces' => (int) ($bounces[$day] ?? 0),
            ];
        }

        return $series;
    }
}
