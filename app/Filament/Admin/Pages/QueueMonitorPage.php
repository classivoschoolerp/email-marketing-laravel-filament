<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\RequiresLogsPermission;
use App\Services\Reports\QueueMonitorService;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Queue monitor (spec §3.27 F-3.27-013, WP-17). Horizon is not installable
 * on this stack (D-04: ext-pcntl) — this page is the documented alternative:
 * live per-queue depth (pending/reserved/oldest age) from the database queue
 * driver plus the failed-job count, polling every 10s.
 */
class QueueMonitorPage extends Page
{
    use RequiresLogsPermission;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'System Health';

    protected static string $view = 'filament.admin.pages.queue-monitor';

    protected static ?string $slug = 'logs/queue-monitor';

    protected static ?int $navigationSort = 117;

    public static function getNavigationGroup(): ?string
    {
        return __('reports.admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.admin.queue_monitor.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('reports.admin.queue_monitor.title');
    }

    public function monitor(): QueueMonitorService
    {
        return app(QueueMonitorService::class);
    }
}
