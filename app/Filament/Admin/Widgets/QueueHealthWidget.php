<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\QueueMonitorPage;
use App\Services\Reports\QueueMonitorService;
use Filament\Widgets\Widget;

/**
 * Queue health widget (spec §3.1 F-3.1-004, WP-18): pending / in-flight /
 * failed jobs and the oldest pending age from the WP-17 QueueMonitorService.
 * Horizon cannot run on this stack (D-04) so there is no true worker
 * registry — jobs currently reserved are the honest "workers active" signal,
 * rendered as such. Visible to admins who may open the queue monitor page.
 */
class QueueHealthWidget extends Widget
{
    protected static string $view = 'filament.admin.widgets.queue-health';

    protected static bool $isLazy = false;

    protected static ?int $sort = 40;

    /** Panel-discovered widget: mirror the queue monitor page's gate. */
    public static function canView(): bool
    {
        return QueueMonitorPage::canAccess();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $monitor = app(QueueMonitorService::class);
        $queues = $monitor->queues();

        $oldest = collect($queues)->pluck('oldest_pending_seconds')->filter()->max();

        return [
            'supported' => $monitor->supported(),
            'pending' => array_sum(array_column($queues, 'pending')),
            'reserved' => array_sum(array_column($queues, 'reserved')),
            'failed' => $monitor->failedCount(),
            'oldestSeconds' => $oldest,
            'monitorUrl' => QueueMonitorPage::getUrl(panel: 'admin'),
        ];
    }
}
