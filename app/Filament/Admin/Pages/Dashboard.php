<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;

/**
 * Admin dashboard (spec §3.1, WP-18): explicit widget line-up — counters,
 * pending items, system-wide engagement chart, top customers, queue + cron
 * health (permission-gated), license status, WP-17 server usage and the
 * activity feed. The header carries the quick support access link
 * (F-3.1-009), pointing at the configured support URL and falling back to a
 * mailto for the configured from-address.
 */
class Dashboard extends BaseDashboard
{
    /** @return array<class-string> */
    public function getWidgets(): array
    {
        // Widgets owned by optional modules drop out when not installed.
        return array_values(array_filter([
            AccountWidget::class,
            Widgets\AdminCountersWidget::class,
            Widgets\PendingItemsWidget::class,
            Widgets\SystemEngagementChart::class,
            Widgets\TopCustomersTable::class,
            Widgets\QueueHealthWidget::class,
            Widgets\CronHealthWidget::class,
            Widgets\LicenseStatusWidget::class,
            // WP-40 (§3.1 F-3.1-006): disk / memory / DB size from
            // SystemInfoService, gated by the system-info page's permission.
            Widgets\ServerResourcesWidget::class,
            Widgets\ServerUsageChart::class,
            Widgets\AdminRecentActivityWidget::class,
        ], 'class_exists'));
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('support')
                ->label(__('dashboards.admin.support_link'))
                ->icon('heroicon-m-lifebuoy')
                ->url($this->supportUrl(), shouldOpenInNewTab: true),
        ];
    }

    public function supportUrl(): string
    {
        $configured = (string) config('emp.support_url', '');

        if ($configured !== '') {
            return $configured;
        }

        return 'mailto:'.(string) config('mail.from.address');
    }
}
