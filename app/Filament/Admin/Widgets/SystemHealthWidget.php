<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\OpsDashboardPage;
use App\Services\Ops\ResourceMonitor;
use Filament\Widgets\Widget;

/**
 * §6.6 F-6.6-005 — disk / memory / database-size widget with threshold
 * colouring. Values and thresholds come from {@see ResourceMonitor}; a metric
 * the host cannot report renders as "—" with the reason, never as 0.
 */
class SystemHealthWidget extends Widget
{
    protected static string $view = 'filament.admin.widgets.system-health';

    protected static bool $isLazy = false;

    protected static ?int $sort = 45;

    protected int|string|array $columnSpan = 2;

    public static function canView(): bool
    {
        return OpsDashboardPage::canAccess();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $monitor = app(ResourceMonitor::class);

        return [
            'checks' => $monitor->checks(),
            'breaches' => count($monitor->breaches()),
        ];
    }
}
