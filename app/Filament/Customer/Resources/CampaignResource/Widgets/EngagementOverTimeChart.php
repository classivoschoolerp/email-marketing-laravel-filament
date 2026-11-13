<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Widgets;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Reports\CampaignReportService;
use Filament\Widgets\ChartWidget;

/**
 * Shared shell for the opens/clicks-over-time report charts (spec §2.3.8
 * F-2.3.8-011/012): hourly/daily toggle, series computed by
 * CampaignReportService, auto-polling while the campaign sends
 * (F-2.3.8-021).
 */
abstract class EngagementOverTimeChart extends ChartWidget
{
    public ?Campaign $record = null;

    public ?string $filter = 'daily';

    protected static ?string $maxHeight = '260px';

    /** opens | clicks */
    abstract protected function metric(): string;

    /** @return array<string, string> */
    protected function getFilters(): ?array
    {
        return [
            'hourly' => __('reports.charts.hourly'),
            'daily' => __('reports.charts.daily'),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getPollingInterval(): ?string
    {
        return $this->record?->status === CampaignStatus::Sending ? '15s' : null;
    }

    protected function getData(): array
    {
        $series = $this->record === null
            ? collect()
            : app(CampaignReportService::class)->series(
                $this->record,
                $this->metric(),
                $this->filter === 'hourly' ? 'hourly' : 'daily',
            );

        return [
            'datasets' => [
                [
                    'label' => $this->getHeading(),
                    'data' => $series->values()->all(),
                    'borderColor' => $this->metric() === 'clicks' ? '#16a34a' : '#2a78d6',
                    'backgroundColor' => $this->metric() === 'clicks'
                        ? 'rgba(22, 163, 74, 0.12)'
                        : 'rgba(42, 120, 214, 0.12)',
                    'borderWidth' => 2,
                    'pointRadius' => 0,
                    'pointHitRadius' => 12,
                    'fill' => true,
                    'tension' => 0.15,
                ],
            ],
            'labels' => $series->keys()->all(),
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
