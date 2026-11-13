<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\CampaignStatus;
use App\Enums\CustomerStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\Subscriber;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Admin dashboard counters (spec §3.1 F-3.1-001, WP-18): customers
 * total/active, lists, subscribers, campaigns with the sent/sending/pending
 * split, delivery servers, and emails sent today / this month (delivered
 * campaign_delivery_logs rows, UTC windows). All queries cross-tenant via
 * withoutTenancy().
 */
class AdminCountersWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    protected function getStats(): array
    {
        $campaigns = Campaign::withoutTenancy()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pendingCampaigns = (int) ($campaigns[CampaignStatus::Scheduled->value] ?? 0)
            + (int) ($campaigns[CampaignStatus::PendingApproval->value] ?? 0);

        $sentToday = $this->deliveredSince(now()->startOfDay());
        $sentThisMonth = $this->deliveredSince(now()->startOfMonth());

        return [
            Stat::make(__('dashboards.admin.counters.customers'), number_format(Customer::query()->count()))
                ->description(__('dashboards.admin.counters.customers_active', [
                    'count' => number_format(Customer::query()->where('status', CustomerStatus::Active->value)->count()),
                ]))
                ->icon('heroicon-o-user-group'),

            Stat::make(__('dashboards.admin.counters.lists'), number_format(EmailList::withoutTenancy()->count()))
                ->icon('heroicon-o-queue-list'),

            Stat::make(__('dashboards.admin.counters.subscribers'), number_format(Subscriber::withoutTenancy()->count()))
                ->icon('heroicon-o-users'),

            Stat::make(__('dashboards.admin.counters.campaigns'), number_format((int) $campaigns->sum()))
                ->description(__('dashboards.admin.counters.campaigns_split', [
                    'sent' => number_format((int) ($campaigns[CampaignStatus::Sent->value] ?? 0)),
                    'sending' => number_format((int) ($campaigns[CampaignStatus::Sending->value] ?? 0)),
                    'pending' => number_format($pendingCampaigns),
                ]))
                ->icon('heroicon-o-paper-airplane'),

            Stat::make(__('dashboards.admin.counters.delivery_servers'), number_format(DeliveryServer::withoutTenancy()->count()))
                ->icon('heroicon-o-server'),

            Stat::make(__('dashboards.admin.counters.emails_today'), number_format($sentToday))
                ->description(__('dashboards.admin.counters.emails_month', ['count' => number_format($sentThisMonth)]))
                ->icon('heroicon-o-envelope'),
        ];
    }

    private function deliveredSince(Carbon $since): int
    {
        return CampaignDeliveryLog::query()
            ->where('status', CampaignDeliveryLog::STATUS_SENT)
            ->where('created_at', '>=', $since)
            ->count();
    }
}
