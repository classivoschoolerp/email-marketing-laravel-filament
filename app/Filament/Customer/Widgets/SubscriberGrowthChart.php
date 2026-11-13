<?php

declare(strict_types=1);

namespace App\Filament\Customer\Widgets;

use App\Models\Subscriber;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

/**
 * Account-wide subscriber growth line (spec §2.1 F-2.1-003, WP-18) with a
 * selectable range (30 days / 12 weeks / 12 months), mirroring the per-list
 * ListGrowthChart bucketing: SQL groups by day (date() exists on SQLite and
 * MySQL, D-03), PHP re-buckets per period. Tenant-scoped via CustomerScope.
 */
class SubscriberGrowthChart extends ChartWidget
{
    public ?string $filter = 'daily';

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '260px';

    protected static ?int $sort = 30;

    public function getHeading(): string
    {
        return __('dashboards.customer.growth.heading');
    }

    /** @return array<string, string> */
    protected function getFilters(): ?array
    {
        return [
            'daily' => __('dashboards.customer.growth.daily'),
            'weekly' => __('dashboards.customer.growth.weekly'),
            'monthly' => __('dashboards.customer.growth.monthly'),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $buckets = $this->buckets();

        return [
            'datasets' => [
                [
                    'label' => __('dashboards.customer.growth.series'),
                    'data' => $buckets->values()->all(),
                    'borderColor' => '#059669',
                    'backgroundColor' => 'rgba(5, 150, 105, 0.12)',
                    'borderWidth' => 2,
                    'pointRadius' => 0,
                    'pointHitRadius' => 12,
                    'fill' => true,
                    'tension' => 0.15,
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

    /**
     * New-subscriber counts per bucket, oldest first, empty buckets filled.
     *
     * @return Collection<string, int>
     */
    public function buckets(): Collection
    {
        $periods = $this->filter === 'daily' ? 30 : 12;

        $start = match ($this->filter) {
            'weekly' => now()->startOfWeek()->subWeeks($periods - 1),
            'monthly' => now()->startOfMonth()->subMonths($periods - 1),
            default => now()->startOfDay()->subDays($periods - 1),
        };

        $daily = Subscriber::query()
            ->selectRaw('date(COALESCE(subscribed_at, created_at)) as day, count(*) as aggregate')
            ->whereRaw('COALESCE(subscribed_at, created_at) >= ?', [$start->format('Y-m-d H:i:s')])
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        $buckets = [];

        for ($i = 0; $i < $periods; $i++) {
            $key = match ($this->filter) {
                'weekly' => $start->copy()->addWeeks($i)->format('Y-m-d'),
                'monthly' => $start->copy()->addMonths($i)->format('Y-m'),
                default => $start->copy()->addDays($i)->format('Y-m-d'),
            };

            $buckets[$key] = 0;
        }

        foreach ($daily as $day => $count) {
            $date = CarbonImmutable::parse((string) $day);

            $key = match ($this->filter) {
                'weekly' => $date->startOfWeek()->format('Y-m-d'),
                'monthly' => $date->format('Y-m'),
                default => $date->format('Y-m-d'),
            };

            if (array_key_exists($key, $buckets)) {
                $buckets[$key] += (int) $count;
            }
        }

        return collect($buckets);
    }
}
