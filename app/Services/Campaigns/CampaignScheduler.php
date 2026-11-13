<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Models\Campaign;
use App\Services\Settings\SettingsReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Schedule / Send-now action (spec §2.3.5 F-2.3.5-009). Runs the pre-flight
 * gate, enforces the recipients cap (§3.25.3 max_campaign_recipients +
 * group matrix), then transitions the campaign to `scheduled` — or to
 * `pending-approval` when the operator requires approval (F-3.25.3-008).
 * The WP-13 sending engine picks up scheduled campaigns whose send_at /
 * next_run_at is due.
 */
class CampaignScheduler
{
    public function __construct(
        private readonly CampaignPreflight $preflight,
        private readonly CampaignStatusMachine $machine,
        private readonly CampaignRecipients $recipients,
        private readonly CampaignQuota $quota,
        private readonly RecurringSchedule $recurring,
    ) {}

    /**
     * @throws ValidationException on pre-flight failure, recipient-cap
     *                             breach or illegal status transition
     */
    public function scheduleOrSend(Campaign $campaign): CampaignStatus
    {
        $failures = $this->preflight->failures($campaign);

        if ($failures !== []) {
            throw ValidationException::withMessages(['campaign' => $failures]);
        }

        $this->assertRecipientCap($campaign);

        if ($campaign->type === CampaignType::Regular && $campaign->send_immediately) {
            $campaign->send_at = Carbon::now();
        }

        if ($campaign->type === CampaignType::Recurring) {
            $campaign->next_run_at = $this->recurring->nextRunAt($campaign);
        }

        if ($campaign->share_reports && $campaign->share_reports_secret === null) {
            $campaign->share_reports_secret = Str::random(40);
        }

        $target = SettingsReader::campaign()->approvalRequired()
            ? CampaignStatus::PendingApproval
            : CampaignStatus::Scheduled;

        $this->machine->transition($campaign, $target);

        return $target;
    }

    private function assertRecipientCap(Campaign $campaign): void
    {
        $customer = $campaign->customer()->withoutGlobalScopes()->first();

        if ($customer === null) {
            return;
        }

        $cap = $this->quota->maxRecipients($customer);

        if ($cap === null) {
            return;
        }

        $count = $this->recipients->count($campaign);

        if ($count > $cap) {
            throw ValidationException::withMessages([
                'campaign' => __('campaigns.errors.too_many_recipients', [
                    'count' => number_format($count),
                    'max' => number_format($cap),
                ]),
            ]);
        }
    }
}
