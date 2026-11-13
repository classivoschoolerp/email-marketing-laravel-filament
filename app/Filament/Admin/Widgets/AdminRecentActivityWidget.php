<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailBlockRequest;
use App\Models\LoginLog;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * System-wide recent activity feed (spec §3.1 F-3.1-007, WP-18): new
 * customer registrations, finished campaign sends, login attempts and email
 * block requests merged into one timeline — the ListOverview merge approach,
 * install-wide.
 */
class AdminRecentActivityWidget extends Widget
{
    protected static string $view = 'filament.admin.widgets.admin-recent-activity';

    protected static bool $isLazy = false;

    protected static ?int $sort = 70;

    /**
     * @return list<array{type: string, subject: string, at: Carbon}>
     */
    public function events(int $limit = 12): array
    {
        $events = [];

        foreach (Customer::query()->orderByDesc('created_at')->limit($limit)->get(['email', 'created_at']) as $customer) {
            $events[] = ['type' => 'customer_registered', 'subject' => (string) $customer->email, 'at' => $customer->created_at];
        }

        $sentCampaigns = Campaign::withoutTenancy()
            ->where('status', CampaignStatus::Sent->value)
            ->whereNotNull('send_finished_at')
            ->orderByDesc('send_finished_at')
            ->limit($limit)
            ->get(['name', 'send_finished_at']);

        foreach ($sentCampaigns as $campaign) {
            $events[] = ['type' => 'campaign_sent', 'subject' => (string) $campaign->name, 'at' => $campaign->send_finished_at];
        }

        foreach (LoginLog::query()->orderByDesc('created_at')->limit($limit)->get(['email', 'result', 'created_at']) as $login) {
            $events[] = [
                'type' => $login->result->value === 'success' ? 'login_success' : 'login_failed',
                'subject' => (string) $login->email,
                'at' => $login->created_at,
            ];
        }

        foreach (EmailBlockRequest::query()->orderByDesc('created_at')->limit($limit)->get(['email', 'created_at']) as $request) {
            $events[] = ['type' => 'block_request', 'subject' => (string) $request->email, 'at' => $request->created_at];
        }

        usort($events, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($events, 0, $limit);
    }
}
