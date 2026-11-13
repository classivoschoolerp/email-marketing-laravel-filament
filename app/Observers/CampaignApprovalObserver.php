<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CampaignStatus;
use App\Filament\Admin\Resources\CampaignOverviewResource;
use App\Mail\CampaignApprovalRequestMail;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Approval-request notification (spec §3.15 F-3.15-007, WP-18): whenever a
 * campaign ENTERS pending-approval — CampaignScheduler routes scheduling
 * there when CampaignSettings.approval_required — every admin user is mailed
 * a queued review request. A model observer (not a scheduler edit): the
 * status machine is the single writer of status, so watching the model event
 * catches every path into pending-approval without touching frozen WP-11/12
 * services.
 */
class CampaignApprovalObserver
{
    public function updated(Campaign $campaign): void
    {
        if (! $campaign->wasChanged('status') || $campaign->status !== CampaignStatus::PendingApproval) {
            return;
        }

        $customer = $campaign->customer()->withoutGlobalScopes()->first();
        $customerName = $customer !== null
            ? ($customer->full_name !== '' ? $customer->full_name : (string) $customer->email)
            : '';

        $reviewUrl = $this->reviewUrl();

        foreach (User::query()->pluck('email')->filter() as $email) {
            Mail::to((string) $email)->queue(new CampaignApprovalRequestMail(
                (string) $campaign->name,
                $customerName,
                $reviewUrl,
            ));
        }
    }

    private function reviewUrl(): string
    {
        try {
            return CampaignOverviewResource::getUrl('index', panel: 'admin');
        } catch (\Throwable) {
            return url('/admin/campaigns-overview');
        }
    }
}
