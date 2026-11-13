<?php

declare(strict_types=1);

namespace App\Filament\Customer\Widgets;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\ExportJob;
use App\Models\ImportJob;
use App\Models\Subscriber;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Recent account activity feed (spec §2.1 F-2.1-006, WP-18): subscriber
 * events, campaign sends and finished imports/exports merged into one
 * timeline — the same merge approach as the §2.2.3 ListOverview timeline,
 * account-wide. Tenant-scoped via CustomerScope.
 */
class RecentActivityWidget extends Widget
{
    protected static string $view = 'filament.customer.widgets.recent-activity';

    protected static bool $isLazy = false;

    protected static ?int $sort = 60;

    /**
     * @return list<array{type: string, subject: string, at: Carbon}>
     */
    public function events(int $limit = 12): array
    {
        $events = [];

        $recentSubscribers = fn (string $column) => Subscriber::query()
            ->whereNotNull($column)
            ->orderByDesc($column)
            ->limit($limit)
            ->get(['email', $column]);

        foreach ($recentSubscribers('subscribed_at') as $subscriber) {
            $events[] = ['type' => 'subscribed', 'subject' => (string) $subscriber->email, 'at' => $subscriber->subscribed_at];
        }

        foreach ($recentSubscribers('unsubscribed_at') as $subscriber) {
            $events[] = ['type' => 'unsubscribed', 'subject' => (string) $subscriber->email, 'at' => $subscriber->unsubscribed_at];
        }

        $sentCampaigns = Campaign::query()
            ->where('status', CampaignStatus::Sent->value)
            ->whereNotNull('send_finished_at')
            ->orderByDesc('send_finished_at')
            ->limit($limit)
            ->get(['name', 'send_finished_at']);

        foreach ($sentCampaigns as $campaign) {
            $events[] = ['type' => 'campaign_sent', 'subject' => (string) $campaign->name, 'at' => $campaign->send_finished_at];
        }

        $imports = ImportJob::query()
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->limit($limit)
            ->with('list')
            ->get();

        foreach ($imports as $import) {
            $events[] = ['type' => 'import_finished', 'subject' => (string) ($import->list?->name ?? ''), 'at' => $import->finished_at];
        }

        $exports = ExportJob::query()
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->limit($limit)
            ->with('list')
            ->get();

        foreach ($exports as $export) {
            $events[] = ['type' => 'export_finished', 'subject' => (string) ($export->list?->name ?? ''), 'at' => $export->finished_at];
        }

        usort($events, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($events, 0, $limit);
    }
}
