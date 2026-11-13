<?php

declare(strict_types=1);

namespace App\Filament\Customer\Pages;

use App\Filament\Customer\Widgets;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;

/**
 * Customer dashboard (spec §2.1, WP-18): explicit widget line-up so the
 * layout is deterministic — announcements and quick actions first, then the
 * counters, quotas, charts, recent campaigns and the activity feed.
 */
class Dashboard extends BaseDashboard
{
    /** @return array<class-string> */
    public function getWidgets(): array
    {
        // Widgets owned by optional modules drop out when not installed.
        return array_values(array_filter([
            // WP-40 (§2.1 F-2.1-007): first while the account still has setup
            // steps left, and self-hiding once it does not.
            Widgets\GettingStartedWidget::class,
            Widgets\AnnouncementsWidget::class,
            Widgets\QuickActionsWidget::class,
            AccountWidget::class,
            Widgets\AccountCountersWidget::class,
            // WP-40 (§2.1 F-2.1-008): the deliverability health score card,
            // summarising the §2.9.9 centre.
            Widgets\DeliverabilityHealthWidget::class,
            Widgets\QuotaOverviewWidget::class,
            Widgets\SubscriberGrowthChart::class,
            Widgets\EngagementChart::class,
            Widgets\RecentCampaignsTable::class,
            Widgets\RecentActivityWidget::class,
        ], 'class_exists'));
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
