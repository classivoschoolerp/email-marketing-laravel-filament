<?php

declare(strict_types=1);

namespace App\Jobs\Sending;

use App\Enums\CampaignStatus;
use App\Enums\NotificationEvent;
use App\Mail\CampaignFinishedMail;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Services\Activity\ActivityRecorder;
use App\Services\Campaigns\AbTest\AbWinnerSelector;
use App\Services\Campaigns\CampaignStatusMachine;
use App\Services\Campaigns\CampaignWebhookNotifier;
use App\Services\Notifications\CustomerNotifier;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Send-wave epilogue (WP-13), dispatched from the Bus::batch finally hook:
 *
 *  - campaign still `sending` + every recipient finalised → status `sent`
 *    (machine), send_finished_at stamped, notify_on_finish mail queued;
 *  - paused/cancelled/blocked mid-wave → clears send_batch_id so
 *    campaigns:send can start a resume wave for the remaining recipients;
 *  - incomplete (deferred jobs never redelivered, worker died) → clears
 *    send_batch_id and leaves the campaign `sending` — the next
 *    campaigns:send tick self-heals with a new wave.
 */
class FinishCampaignSendJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly int $campaignId)
    {
        $this->onQueue(Queues::CAMPAIGNS_HIGH);
    }

    public function handle(CampaignStatusMachine $machine): void
    {
        $campaign = Campaign::withoutTenancy()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        if (in_array($campaign->status, [CampaignStatus::Paused, CampaignStatus::Cancelled, CampaignStatus::Blocked], true)) {
            $this->clearBatchHandle($campaign);

            return;
        }

        if ($campaign->status !== CampaignStatus::Sending) {
            return;
        }

        $finalised = CampaignDeliveryLog::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', '!=', CampaignDeliveryLog::STATUS_RETRYING)
            ->count();

        if ($finalised < (int) $campaign->delivery_total) {
            // Deferred/lost units remain — let campaigns:send start a new wave.
            $this->clearBatchHandle($campaign);

            return;
        }

        // WP-35 (§2.3.6 F-2.3.6-007/011): an A/B campaign whose TEST wave just
        // finished is not sent — it is waiting for a winner. Stamp the moment
        // the decision clock starts from and park the batch handle in a
        // sentinel campaigns:send does not treat as resumable, so no wave is
        // started until AbWinnerSelector releases it.
        if ($campaign->ab_enabled && $campaign->ab_phase === 'test') {
            Campaign::withoutTenancy()->whereKey($campaign->id)->update([
                'ab_test_finished_at' => $campaign->ab_test_finished_at ?? now(),
                'send_batch_id' => AbWinnerSelector::WAITING_HANDLE,
            ]);

            return;
        }

        $machine->transition($campaign, CampaignStatus::Sent);
        $campaign->forceFill(['send_finished_at' => now()])->saveQuietly();

        // F-2.3.4-022 — per-campaign webhook (queued, failure-swallowing).
        app(CampaignWebhookNotifier::class)->fire($campaign, CampaignWebhookNotifier::EVENT_FINISHED);

        $owner = $campaign->customer()->withoutGlobalScopes()->first();

        // WP-39 §2.15.3 / F-4.3-002: the account hears about a finished
        // campaign in the bell and/or by email, per its own preferences.
        // The per-campaign notify_on_finish flag still gates the EMAIL (it
        // is a campaign-level opt-in that predates preferences); the in-app
        // notification is governed by the preference alone.
        if ($owner !== null) {
            $notifier = app(CustomerNotifier::class);

            $notifier->inApp(
                $owner,
                NotificationEvent::CampaignFinished,
                __('notifications.in_app.campaign_finished.title'),
                __('notifications.in_app.campaign_finished.body', [
                    'campaign' => (string) $campaign->name,
                    'count' => (int) $campaign->delivery_sent,
                ]),
                status: 'success',
                icon: 'heroicon-o-paper-airplane',
            );

            app(ActivityRecorder::class)->record(
                'campaign.sent',
                $campaign,
                properties: [
                    'sent' => (int) $campaign->delivery_sent,
                    'failed' => (int) $campaign->delivery_failed,
                ],
                customer: $owner,
            );

            $email = $owner->email;

            if ($campaign->notify_on_finish
                && $email !== null
                && trim((string) $email) !== ''
                && $notifier->allowsEmail($owner, NotificationEvent::CampaignFinished)) {
                Mail::to($email)->queue(new CampaignFinishedMail(
                    (string) $campaign->name,
                    (int) $campaign->delivery_sent,
                    (int) $campaign->delivery_failed,
                    (int) $campaign->delivery_total,
                ));
            }
        }
    }

    private function clearBatchHandle(Campaign $campaign): void
    {
        Campaign::withoutTenancy()->whereKey($campaign->id)->update(['send_batch_id' => null]);
    }
}
