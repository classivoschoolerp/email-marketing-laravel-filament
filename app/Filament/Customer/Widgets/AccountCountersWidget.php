<?php

declare(strict_types=1);

namespace App\Filament\Customer\Widgets;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Models\Subscriber;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Customer dashboard counters (spec §2.1 F-2.1-001, WP-18): lists, total
 * subscribers with the active/unsubscribed/bounced split, campaigns
 * sent/pending, templates and delivery servers. All queries run under the
 * CustomerScope tenant scope; servers use the browsable() visibility set
 * (own + assigned system servers).
 */
class AccountCountersWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    protected function getStats(): array
    {
        $subscribers = Subscriber::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Active membership = confirmed + unconfirmed (SubscriberStatus::isActive).
        $active = (int) ($subscribers['confirmed'] ?? 0) + (int) ($subscribers['unconfirmed'] ?? 0);

        return [
            Stat::make(__('dashboards.customer.counters.lists'), number_format(EmailList::query()->count()))
                ->icon('heroicon-o-queue-list'),

            Stat::make(__('dashboards.customer.counters.subscribers'), number_format((int) $subscribers->sum()))
                ->description(__('dashboards.customer.counters.subscribers_split', [
                    'active' => number_format($active),
                    'unsubscribed' => number_format((int) ($subscribers['unsubscribed'] ?? 0)),
                    'bounced' => number_format((int) ($subscribers['bounced'] ?? 0)),
                ]))
                ->icon('heroicon-o-users'),

            Stat::make(
                __('dashboards.customer.counters.campaigns_sent'),
                number_format(Campaign::query()->where('status', CampaignStatus::Sent->value)->count()),
            )->icon('heroicon-o-paper-airplane'),

            Stat::make(
                __('dashboards.customer.counters.campaigns_pending'),
                number_format(Campaign::query()->whereIn('status', [
                    CampaignStatus::Scheduled->value,
                    CampaignStatus::PendingApproval->value,
                ])->count()),
            )->icon('heroicon-o-clock'),

            Stat::make(__('dashboards.customer.counters.templates'), number_format(EmailTemplate::query()->count()))
                ->icon('heroicon-o-rectangle-group'),

            Stat::make(__('dashboards.customer.counters.delivery_servers'), number_format(DeliveryServer::browsable()->count()))
                ->icon('heroicon-o-server'),
        ];
    }
}
