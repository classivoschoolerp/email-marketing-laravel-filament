<?php

declare(strict_types=1);

namespace App\Jobs\Sending;

use App\Enums\AutoresponderTrigger;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\SubscriberStatus;
use App\Events\Campaign\CampaignMessageSending;
use App\Events\Campaign\CampaignMessageSent;
use App\Jobs\Concerns\IdempotentJob;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignVariant;
use App\Models\Subscriber;
use App\Sending\DriverRegistry;
use App\Sending\MimeBuilder;
use App\Sending\Pipeline\CampaignAutoPause;
use App\Sending\Pipeline\CampaignEmailFactory;
use App\Sending\Pipeline\SendWindow;
use App\Sending\RetryPolicy;
use App\Sending\ServerPicker;
use App\Sending\ServerUsage;
use App\Services\Blacklist\BlacklistSendGuard;
use App\Services\SpamCheck\SpamCheckerInterface;
use App\Support\Queue\Queues;
use App\Support\RateLimit\HourlyWindowLimiter;
use App\Support\Stats\LiveCounter;
use Carbon\CarbonInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * Sends one campaign to one subscriber (WP-13, the send path §0.6/§4.6).
 *
 * Idempotency (docs/ARCHITECTURE.md rule 6, F-0.4-004):
 *  1. job key `send:{campaign_uid}:{subscriber_uid}` (Idempotent middleware) —
 *     at-most-once across parallel workers / redeliveries;
 *  2. campaign_delivery_logs unique(campaign_id, subscriber_id) — the DB
 *     arbiter even if the key cache is lost (F-4.6-002).
 *
 * Deferral paths (send-window closed, campaign send-rate exhausted, D-50
 * server pause, paused/cancelled campaign) exit WITHOUT consuming the key —
 * the unit stays unsent and the every-minute campaigns:send wave re-dispatches
 * it once the blocker clears (a released batch job would keep its Bus::batch
 * open forever, so wave-level retry is the deferral mechanism).
 *
 * Per-recipient transient failures ARE released with the §3.25.3 RetryPolicy
 * backoff (real retry semantics); the delivery-log row carries the true
 * driver attempt count (`retries`) independent of queue redeliveries.
 */
class SendToSubscriberJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public int $retryUntilTimestamp;

    public function __construct(
        public readonly int $campaignId,
        public readonly int $subscriberId,
        public readonly string $campaignUid,
        public readonly string $subscriberUid,
        public readonly string $occurrence = '',
        /** WP-35 §2.3.6: the A/B variant this recipient was assigned, if any. */
        public readonly ?int $variantId = null,
    ) {
        $this->onQueue(Queues::CAMPAIGNS_SEND);
        $this->retryUntilTimestamp = Carbon::now()->addDay()->getTimestamp();
    }

    /** Deferral horizon — releases do not count against a tries limit. */
    public function retryUntil(): CarbonInterface
    {
        return Carbon::createFromTimestamp($this->retryUntilTimestamp);
    }

    public function handle(
        CampaignEmailFactory $factory,
        ServerPicker $picker,
        DriverRegistry $registry,
        ServerUsage $usage,
        HourlyWindowLimiter $limiter,
        SendWindow $window,
        SpamCheckerInterface $spam,
        MimeBuilder $mime,
        LiveCounter $counter,
        CampaignAutoPause $autoPause,
    ): void {
        if ($this->batch()?->cancelled()) {
            $this->releaseIdempotencyKey();

            return;
        }

        $campaign = Campaign::withoutTenancy()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        // Pause / cancel honored per job: give the key back so a resume wave
        // can re-dispatch this unit.
        if ($campaign->status !== CampaignStatus::Sending) {
            $this->releaseIdempotencyKey();

            return;
        }

        // Send-window closed mid-send — defer to a later wave.
        if (! $window->isOpen($campaign)) {
            $this->releaseIdempotencyKey();

            return;
        }

        // Campaign hourly send-rate throttle (F-2.3.4-004) — defer to a later wave.
        $rate = (int) ($campaign->send_rate_per_hour ?? 0);
        $rateKey = 'campaign-rate:'.$campaign->uid;

        if ($rate > 0 && $limiter->wouldExceed($rateKey, $rate)) {
            $this->releaseIdempotencyKey();

            return;
        }

        // Second idempotency arbiter: claim the delivery-log row.
        $log = $this->claimLog($campaign);

        if ($log === null) {
            return; // already sent/failed/skipped elsewhere
        }

        $subscriber = Subscriber::withoutTenancy()->find($this->subscriberId);

        if ($subscriber === null || ! $this->subscriberEligible($campaign, $subscriber)) {
            $this->finalize($campaign, $log, CampaignDeliveryLog::STATUS_SKIPPED, response: __('sending.log.subscriber_ineligible'));
            $autoPause->evaluate($campaign->refresh());

            return;
        }

        // WP-16 (§3.17): global-blacklist pre-send guard — the ONE blacklist
        // hook in the send pipeline. Active only when BlacklistSettings
        // apply_at ∈ send|both; matches exact/domain/regex rules with the
        // shared-vs-isolated semantics of BlacklistMatcher.
        if (app(BlacklistSendGuard::class)->blocked($subscriber)) {
            $this->finalize($campaign, $log, CampaignDeliveryLog::STATUS_SKIPPED, response: __('blacklist.log.send_skipped'));
            $autoPause->evaluate($campaign->refresh());

            return;
        }

        $message = $factory->make($campaign, $subscriber, $this->variant());

        // Delivery server: campaign restriction + quotas + D-50 pause-vs-next.
        $customer = $campaign->customer()->withoutGlobalScopes()->first();

        if ($customer === null) {
            $this->finalize($campaign, $log, CampaignDeliveryLog::STATUS_FAILED, response: __('sending.log.no_customer'));

            return;
        }

        // F-2.9.2-038 (WP-42): the campaign + its target lists are handed to
        // the picker so servers assigned to a specific set of lists/campaigns
        // are skipped for everything else.
        $pick = $picker->pickFor(
            $customer,
            1,
            (array) ($campaign->delivery_server_ids ?? []),
            campaignId: (int) $campaign->id,
            listIds: $campaign->targetListIds(),
        );

        if ($pick->shouldPause) {
            // D-50: an exhausted PAUSE server halts traffic — defer, do not
            // fail over. The claim row is retired so a later wave retries.
            $log->delete();
            $this->releaseIdempotencyKey();

            return;
        }

        $server = $pick->server;

        if ($server === null) {
            $this->finalize($campaign, $log, CampaignDeliveryLog::STATUS_FAILED, response: __('sending.log.no_server'));
            $autoPause->evaluate($campaign->refresh());

            return;
        }

        // D-49 SpamAssassin gate on the full RFC 2822 message.
        $check = $spam->check($mime->build($message, $server)->toString());

        if ($check->blocked) {
            $this->finalize($campaign, $log, CampaignDeliveryLog::STATUS_SKIPPED, server: $server->id, response: __('sending.log.spam_blocked', [
                'score' => number_format($check->score, 1),
                'required' => number_format($check->requiredScore, 1),
            ]));
            $autoPause->evaluate($campaign->refresh());

            return;
        }

        // WP-36 (§6.1 F-6.1-012) — HOOK `campaign.pre_send`. The last point
        // before the driver takes the message: listeners may mutate it (add
        // headers, rewrite content) or cancel this one recipient. A cancelled
        // unit is finalised as `skipped`, exactly like the blacklist guard,
        // so the progress counters still converge. With no listener bound
        // this is a single no-op dispatch.
        $sending = new CampaignMessageSending($campaign, $subscriber, $server, $message);
        event($sending);

        if ($sending->isCancelled()) {
            $this->finalize(
                $campaign,
                $log,
                CampaignDeliveryLog::STATUS_SKIPPED,
                server: $server->id,
                response: $sending->cancelReason() ?? __('sending.log.plugin_cancelled'),
            );
            $autoPause->evaluate($campaign->refresh());

            return;
        }

        $policy = RetryPolicy::fromSettings();
        $attempt = (int) $log->retries + 1;

        $result = $registry->forServer($server)->send($message, $server);

        if ($result->ok) {
            $usage->recordSend($server);

            if ($rate > 0) {
                $limiter->hit($rateKey);
            }

            $this->finalize(
                $campaign,
                $log,
                CampaignDeliveryLog::STATUS_SENT,
                server: $server->id,
                messageId: $result->messageId,
                retries: $attempt - 1,
            );

            $counter->increment('track:sent');
            $counter->increment('campaigns:'.$campaign->uid.':sent');

            // WP-36 (§6.1 F-6.1-012) — HOOK `campaign.post_send`, fired only
            // once the delivery log is finalised so a listener that reads it
            // back sees the committed row.
            CampaignMessageSent::dispatch($campaign, $subscriber, $server, $result->messageId);

            $autoPause->evaluate($campaign->refresh());

            return;
        }

        $usage->recordFailure($server);

        if ($result->transient && $policy->shouldRetry($result, $attempt)) {
            $log->forceFill([
                'status' => CampaignDeliveryLog::STATUS_RETRYING,
                'delivery_server_id' => $server->id,
                'retries' => $attempt,
                'response' => (string) $result->error,
            ])->save();

            $this->release($policy->delayForAttempt($attempt));

            return;
        }

        $this->finalize(
            $campaign,
            $log,
            CampaignDeliveryLog::STATUS_FAILED,
            server: $server->id,
            response: (string) $result->error,
            retries: $attempt - 1,
        );
        $autoPause->evaluate($campaign->refresh());
    }

    /** Defensive finalizer for unexpected exhaustion/exceptions. */
    public function failed(?\Throwable $exception): void
    {
        $log = CampaignDeliveryLog::query()
            ->where('campaign_id', $this->campaignId)
            ->where('subscriber_id', $this->subscriberId)
            ->first();

        if ($log !== null && $log->status === CampaignDeliveryLog::STATUS_RETRYING) {
            $campaign = Campaign::withoutTenancy()->find($this->campaignId);

            if ($campaign !== null) {
                $this->finalize($campaign, $log, CampaignDeliveryLog::STATUS_FAILED, response: (string) $exception?->getMessage());
            }
        }
    }

    /**
     * The A/B variant to render (WP-35 §2.3.6). The id is carried on the job
     * so the hot path costs nothing for non-A/B campaigns; it is re-read from
     * the durable assignment row only when the job was queued without one
     * (an in-flight job from before the winner phase started).
     */
    private function variant(): ?CampaignVariant
    {
        if ($this->variantId === null) {
            return null;
        }

        return CampaignVariant::query()
            ->whereKey($this->variantId)
            ->where('campaign_id', $this->campaignId)
            ->first();
    }

    public function idempotencyKey(): string
    {
        $key = 'send:'.$this->campaignUid.':'.$this->subscriberUid;

        // Repeating autoresponders (yearly date-field triggers with
        // once-per-subscriber off) qualify the key per occurrence.
        return $this->occurrence === '' ? $key : $key.':'.$this->occurrence;
    }

    /**
     * Insert the claim row; a unique-constraint hit means another worker owns
     * (or owned) this unit — only a row parked in `retrying` may be resumed.
     */
    private function claimLog(Campaign $campaign): ?CampaignDeliveryLog
    {
        try {
            return CampaignDeliveryLog::query()->create([
                'campaign_id' => $campaign->id,
                'subscriber_id' => $this->subscriberId,
                'status' => CampaignDeliveryLog::STATUS_RETRYING,
                'retries' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = CampaignDeliveryLog::query()
                ->where('campaign_id', $campaign->id)
                ->where('subscriber_id', $this->subscriberId)
                ->first();

            return $existing !== null && $existing->status === CampaignDeliveryLog::STATUS_RETRYING
                ? $existing
                : null;
        }
    }

    /**
     * Regular sends only ever target confirmed members; the after-unsubscribe
     * autoresponder legitimately mails just-unsubscribed people.
     */
    private function subscriberEligible(Campaign $campaign, Subscriber $subscriber): bool
    {
        if ($campaign->type === CampaignType::Autoresponder
            && $campaign->ar_trigger === AutoresponderTrigger::AfterUnsubscribe) {
            return $subscriber->status === SubscriberStatus::Unsubscribed;
        }

        return $subscriber->status === SubscriberStatus::Confirmed;
    }

    private function finalize(
        Campaign $campaign,
        CampaignDeliveryLog $log,
        string $status,
        ?int $server = null,
        ?string $messageId = null,
        ?string $response = null,
        int $retries = 0,
    ): void {
        $log->forceFill([
            'status' => $status,
            'delivery_server_id' => $server ?? $log->delivery_server_id,
            'message_id' => $messageId,
            'response' => $response,
            'retries' => max((int) $log->retries, $retries),
        ])->save();

        // Counters (F-4.6-004): skipped rows count against `failed` so the
        // progress bar converges on delivery_total.
        $column = $status === CampaignDeliveryLog::STATUS_SENT ? 'delivery_sent' : 'delivery_failed';

        Campaign::withoutTenancy()->whereKey($campaign->id)->increment($column);
    }
}
