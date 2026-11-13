<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Widgets;

use App\Models\EmailList;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

/**
 * List growth chart (spec F-2.2.3-002) with daily / weekly / monthly views.
 * Grouping happens in SQL by day (date() exists on both SQLite and MySQL,
 * D-03: no driver-specific SQL) and is re-bucketed per period in PHP.
 */
class ListGrowthChart extends ChartWidget
{
    public ?EmailList $record = null;

    public ?string $filter = 'daily';

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('lists.overview.growth_heading');
    }

    /** @return array<string, string> */
    protected function getFilters(): ?array
    {
        return [
            'daily' => __('lists.overview.growth_daily'),
            'weekly' => __('lists.overview.growth_weekly'),
            'monthly' => __('lists.overview.growth_monthly'),
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
                    'label' => __('lists.overview.growth_series'),
                    'data' => $buckets->values()->all(),
                    'borderColor' => '#2a78d6',
                    'backgroundColor' => 'rgba(42, 120, 214, 0.12)',
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
     * New-subscriber counts per period bucket, oldest first, empty buckets
     * included so the axis is continuous.
     *
     * @return Collection<string, int>
     */
    public function buckets(): Collection
    {
        $periods = match ($this->filter) {
            'weekly' => 12,
            'monthly' => 12,
            default => 30,
        };

        $start = match ($this->filter) {
            'weekly' => now()->startOfWeek()->subWeeks($periods - 1),
            'monthly' => now()->startOfMonth()->subMonths($periods - 1),
            default => now()->startOfDay()->subDays($periods - 1),
        };

        $daily = $this->record === null ? collect() : $this->record->subscribers()
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
