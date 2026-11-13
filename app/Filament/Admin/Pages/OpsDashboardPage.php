<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\RequiresLogsPermission;
use App\Filament\Admin\Widgets\SystemErrorsWidget;
use App\Filament\Admin\Widgets\SystemHealthWidget;
use App\Models\CronRun;
use App\Services\Ops\PerformanceMonitor;
use App\Services\Ops\WorkerRegistry;
use App\Services\Reports\SystemInfoService;
use App\Support\Cache\SmartCache;
use App\Support\System\Cpu;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * §6.6 F-6.6-001 — operations dashboard: one page that consolidates the live
 * system overview an operator needs (versions/drivers, disk + memory + DB
 * size with thresholds, queue depth, worker nodes, cron freshness, cache
 * layer, detected CPU cores and the suggested worker layout).
 *
 * It deliberately REUSES the WP-17 services (SystemInfoService,
 * QueueMonitorService via PerformanceMonitor) instead of duplicating their
 * introspection, and the §3.1 admin Dashboard keeps its business widgets.
 */
class OpsDashboardPage extends Page
{
    use RequiresLogsPermission;

    protected static ?string $navigationIcon = 'heroicon-o-heart';
    protected static ?string $navigationGroup = 'Overview';

    protected static string $view = 'filament.admin.pages.ops-dashboard';

    protected static ?string $slug = 'ops';

    protected static ?int $navigationSort = 110;

    public static function getNavigationGroup(): ?string
    {
        return __('reports.admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.dashboard.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('ops.dashboard.title');
    }

    /** @return array<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [
            SystemHealthWidget::class,
            SystemErrorsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 3;
    }

    public function info(): SystemInfoService
    {
        return app(SystemInfoService::class);
    }

    /** @return array<string, mixed> */
    public function performance(): array
    {
        return app(PerformanceMonitor::class)->summary();
    }

    /** @return array{cores: int, source: string, suggested: array<string, int>} */
    public function cpu(): array
    {
        return [
            'cores' => Cpu::cores(),
            'source' => Cpu::source(),
            'suggested' => Cpu::suggestedWorkers(),
        ];
    }

    /** @return array{enabled: bool, taggable: bool, driver: string} */
    public function cacheLayer(): array
    {
        return [
            'enabled' => SmartCache::enabled(),
            'taggable' => SmartCache::taggable(),
            'driver' => (string) config('cache.default'),
        ];
    }

    /** @return array{alive: int, draining: bool} */
    public function workers(): array
    {
        $registry = app(WorkerRegistry::class);

        return [
            'alive' => $registry->alive()->count(),
            'draining' => $registry->draining(),
        ];
    }

    /** Most recent cron run per job — the "no manual intervention" proof. */
    public function recentCronRuns(int $limit = 8): \Illuminate\Support\Collection
    {
        return CronRun::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
