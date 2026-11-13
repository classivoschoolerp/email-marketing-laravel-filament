<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\DeliveryServerUsagePage;
use App\Models\DeliveryServer;
use App\Models\DeliveryServerUsage;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Per-server send volume over time (spec §3.27 F-3.27-004, WP-17): one line
 * per delivery server (top 8 by volume) over the last 30 days, from the
 * WP-14 delivery_server_usage daily rollups.
 */
class ServerUsageChart extends ChartWidget
{
    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 'full';

    private const COLORS = [
        '#2a78d6', '#16a34a', '#d97706', '#dc2626',
        '#7c3aed', '#0891b2', '#be185d', '#65a30d',
    ];

    /** Panel-discovered widget: keep it off the dashboard of unpermitted admins. */
    public static function canView(): bool
    {
        return DeliveryServerUsagePage::canAccess();
    }

    public function getHeading(): string
    {
        return __('reports.admin.server_usage.chart_heading');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $start = Carbon::now()->startOfDay()->subDays(29);

        $days = [];

        for ($i = 0; $i < 30; $i++) {
            $days[] = $start->copy()->addDays($i)->format('Y-m-d');
        }

        $rows = DeliveryServerUsage::query()
            ->where('date', '>=', $start)
            ->get(['delivery_server_id', 'date', 'sent']);

        $byServer = $rows->groupBy('delivery_server_id')
            ->sortByDesc(fn ($usage): int => (int) $usage->sum('sent'))
            ->take(8);

        $names = DeliveryServer::withoutTenancy()
            ->whereIn('id', $byServer->keys())
            ->pluck('name', 'id');

        $datasets = [];
        $index = 0;

        foreach ($byServer as $serverId => $usage) {
            $daily = $usage->keyBy(fn (DeliveryServerUsage $row): string => $row->date->format('Y-m-d'));

            $datasets[] = [
                'label' => (string) ($names[$serverId] ?? ('#'.$serverId)),
                'data' => array_map(fn (string $day): int => (int) ($daily[$day]->sent ?? 0), $days),
                'borderColor' => self::COLORS[$index % count(self::COLORS)],
                'backgroundColor' => 'transparent',
                'borderWidth' => 2,
                'pointRadius' => 0,
                'pointHitRadius' => 10,
                'tension' => 0.15,
            ];

            $index++;
        }

        return [
            'datasets' => $datasets,
            'labels' => $days,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
