<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\BlockRequestStatus;
use App\Filament\Admin\Resources\EmailBlockRequestResource;
use App\Filament\Admin\Resources\FailedJobResource;
use App\Models\EmailBlockRequest;
use App\Models\FailedJob;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Pending items (spec §3.1 F-3.1-010, WP-18): customers awaiting approval,
 * email block requests awaiting review, and failed queue jobs. Failed
 * payments arrive with the billing work package — the failed-jobs count is
 * the operational pending item this wave.
 */
class PendingItemsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 15;

    protected function getStats(): array
    {
        // Awaiting review = not yet approved/rejected (pending + confirmed).
        $blockRequests = EmailBlockRequest::query()->whereIn('status', [
            BlockRequestStatus::Pending->value,
            BlockRequestStatus::Confirmed->value,
        ])->count();
        $failedJobs = FailedJob::query()->count();

        return [

            Stat::make(__('dashboards.admin.pending.block_requests'), number_format($blockRequests))
                ->icon('heroicon-o-hand-raised')
                ->color($blockRequests > 0 ? 'warning' : 'success')
                ->url(EmailBlockRequestResource::getUrl('index', panel: 'admin')),

            Stat::make(__('dashboards.admin.pending.failed_jobs'), number_format($failedJobs))
                ->icon('heroicon-o-exclamation-triangle')
                ->color($failedJobs > 0 ? 'danger' : 'success')
                ->url(FailedJobResource::getUrl('index', panel: 'admin')),
        ];
    }
}
