<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\QueueMonitorPage;
use App\Models\CronRun;
use App\Services\Cron\HeartbeatMonitor;
use App\Settings\CronSettings;
use Filament\Widgets\Widget;

/**
 * Cron health widget (spec §3.1 F-3.1-005, WP-18): last run per known
 * scheduler job (cron_runs, written by CronRunRecorder) plus the overdue
 * signal from the WP-13 HeartbeatMonitor — when the heartbeat is stale every
 * job is effectively overdue and the widget says so. Jobs that never ran
 * are badged explicitly.
 */
class CronHealthWidget extends Widget
{
    protected static string $view = 'filament.admin.widgets.cron-health';

    protected static bool $isLazy = false;

    protected static ?int $sort = 50;

    /** Panel-discovered widget: mirror the monitoring pages' gate. */
    public static function canView(): bool
    {
        return QueueMonitorPage::canAccess();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $monitor = app(HeartbeatMonitor::class);

        // Latest run per job in one query: newest rows first, first one wins.
        $latest = CronRun::query()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->unique('job')
            ->keyBy('job');

        $jobs = [];

        foreach (CronSettings::installedJobs() as $job) {
            $run = $latest->get($job);

            $jobs[$job] = [
                'last_run' => $run?->started_at,
                'status' => $run?->status,
                'never_ran' => $run === null,
            ];
        }

        return [
            'jobs' => $jobs,
            'stale' => $monitor->isStale(),
            'lastSeen' => $monitor->lastSeenAt(),
        ];
    }
}
