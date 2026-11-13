<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Widgets;

use App\Models\Campaign;
use App\Services\Reports\CampaignReportService;
use Filament\Widgets\ChartWidget;

/**
 * Doughnut split for the device / OS / browser / email-client reports
 * (spec §2.3.8 F-2.3.8-014/015/016) — paired with the share table rendered
 * by the report page view. Null UA columns land in the "unknown" slice.
 */
class EngagementBreakdownChart extends ChartWidget
{
    public ?Campaign $record = null;

    /** device | os | browser | client */
    public string $dimension = 'device';

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '220px';

    private const COLORS = [
        '#2a78d6', '#16a34a', '#d97706', '#dc2626', '#7c3aed',
        '#0891b2', '#be185d', '#65a30d', '#475569', '#9ca3af',
    ];

    public function getHeading(): string
    {
        return __('reports.breakdowns.'.$this->dimension);
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = $this->record === null
            ? []
            : app(CampaignReportService::class)->breakdown($this->record, $this->dimension);

        $rows = array_slice($rows, 0, 10);

        return [
            'datasets' => [
                [
                    'data' => array_column($rows, 'count'),
                    'backgroundColor' => array_slice(self::COLORS, 0, max(1, count($rows))),
                ],
            ],
            'labels' => array_map(
                fn (array $row): string => $row['label'] === 'unknown'
                    ? __('reports.breakdowns.unknown')
                    : $row['label'],
                $rows,
            ),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
