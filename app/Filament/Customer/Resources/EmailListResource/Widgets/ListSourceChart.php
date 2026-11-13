<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Widgets;

use App\Enums\SubscriberSource;
use App\Models\EmailList;
use Filament\Widgets\ChartWidget;

/**
 * Subscribers-by-source breakdown (spec F-2.2.3-003): form, import, API,
 * manual, automation. Categorical hues are assigned in fixed enum order,
 * never cycled, so a source keeps its color as the mix changes.
 */
class ListSourceChart extends ChartWidget
{
    /** Fixed categorical slots (validated palette), keyed by source value. */
    public const COLORS = [
        'form' => '#2a78d6',
        'import' => '#eb6834',
        'api' => '#1baf7a',
        'manual' => '#eda100',
        'automation' => '#e87ba4',
    ];

    public ?EmailList $record = null;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return __('lists.overview.source_heading');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = $this->sourceCounts();

        $labels = [];
        $data = [];
        $colors = [];

        foreach (SubscriberSource::cases() as $source) {
            $labels[] = $source->label();
            $data[] = $counts[$source->value];
            $colors[] = self::COLORS[$source->value];
        }

        return [
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => $colors,
                    // 2px surface gap between segments.
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
        ];
    }

    /** @return array<string, int> source value => count */
    public function sourceCounts(): array
    {
        $counts = $this->record === null ? [] : $this->record->subscribers()
            ->selectRaw('source, count(*) as aggregate')
            ->groupBy('source')
            ->pluck('aggregate', 'source')
            ->all();

        $result = [];

        foreach (SubscriberSource::cases() as $source) {
            $result[$source->value] = (int) ($counts[$source->value] ?? 0);
        }

        return $result;
    }
}
