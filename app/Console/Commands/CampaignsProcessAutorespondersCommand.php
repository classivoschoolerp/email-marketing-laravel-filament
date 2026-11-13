<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCronJob;
use App\Enums\AutoresponderTrigger;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\SubscriberStatus;
use App\Jobs\Sending\SendToSubscriberJob;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackOpen;
use App\Models\CronRun;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Campaigns\CampaignStatusMachine;
use App\Services\Settings\SettingsReader;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `campaigns:process-autoresponders` (spec §0.6 F-0.6-002, every minute).
 * For every active autoresponder campaign the trigger event timestamp is
 * computed per subscriber, the ar_delay applied, ar_send_time honored (the
 * due moment is moved to that time-of-day in the campaign TZ, next day when
 * already past) and due recipients are dispatched through the same
 * SendToSubscriberJob as regular sends.
 *
 * ar_once_per_subscriber uses the delivery log as memory: with it ON a
 * subscriber is served exactly once (the unique constraint enforces it);
 * with it OFF (yearly date-field anniversaries, repeated profile updates)
 * the stale log row of a previous occurrence is retired and the job key is
 * qualified with the occurrence tag.
 *
 * `after-profile-update` uses subscribers.updated_at (> created_at) as the
 * update signal — the schema has no dedicated profile_updated_at column, so
 * any subscriber row update counts (documented approximation).
 */
class CampaignsProcessAutorespondersCommand extends Command
{
    use RunsAsCronJob;

    public const JOB_NAME = 'campaigns:process-autoresponders';

    protected $signature = 'campaigns:process-autoresponders';

    protected $description = 'Dispatch due autoresponder emails per trigger.';

    protected function process(CronRun $run): int
    {
        $machine = app(CampaignStatusMachine::class);
        $dispatched = 0;

        $campaigns = Campaign::withoutTenancy()
            ->where('type', CampaignType::Autoresponder)
            ->whereIn('status', [CampaignStatus::Scheduled->value, CampaignStatus::Sending->value])
            ->whereNotNull('ar_trigger')
            ->get();

        foreach ($campaigns as $campaign) {
            // Activation: a scheduled autoresponder becomes perpetually sending.
            if ($campaign->status === CampaignStatus::Scheduled) {
                $machine->transition($campaign, CampaignStatus::Sending);
                $campaign->forceFill(['send_started_at' => $campaign->send_started_at ?? Carbon::now()])->saveQuietly();
            }

            $dispatched += $this->processCampaign($campaign);
        }

        return $dispatched;
    }

    private function processCampaign(Campaign $campaign): int
    {
        $now = Carbon::now();
        $limit = $this->batchLimit();

        /** @var Collection<int, array{subscriber: Subscriber, event: Carbon, occurrence: string}> $candidates */
        $candidates = match ($campaign->ar_trigger) {
            AutoresponderTrigger::AfterSubscribe => $this->afterSubscribe($campaign),
            AutoresponderTrigger::AfterUnsubscribe => $this->afterUnsubscribe($campaign),
            AutoresponderTrigger::OnDateField => $this->onDateField($campaign, $now),
            AutoresponderTrigger::AfterProfileUpdate => $this->afterProfileUpdate($campaign),
            AutoresponderTrigger::AfterCampaignOpen => $this->afterCampaignEvent($campaign, 'open'),
            AutoresponderTrigger::AfterCampaignSent => $this->afterCampaignEvent($campaign, 'sent'),
            default => collect(),
        };

        $dispatched = 0;

        foreach ($candidates as $candidate) {
            if ($dispatched >= $limit) {
                break;
            }

            $due = $this->dueAt($campaign, $candidate['event']);

            if ($due->greaterThan($now)) {
                continue;
            }

            if (! $this->clearForDispatch($campaign, $candidate['subscriber'], $candidate['occurrence'], $candidate['event'])) {
                continue;
            }

            Campaign::withoutTenancy()->whereKey($campaign->id)->increment('delivery_total');

            SendToSubscriberJob::dispatch(
                $campaign->id,
                (int) $candidate['subscriber']->id,
                (string) $campaign->uid,
                (string) $candidate['subscriber']->uid,
                $campaign->ar_once_per_subscriber ? '' : $candidate['occurrence'],
            );

            $dispatched++;
        }

        return $dispatched;
    }

    /* ---------------------------------------------------------------- *
     *  Trigger candidate computation.
     * ---------------------------------------------------------------- */

    /** @return Collection<int, array{subscriber: Subscriber, event: Carbon, occurrence: string}> */
    private function afterSubscribe(Campaign $campaign): Collection
    {
        $query = Subscriber::withoutTenancy()
            ->where('list_id', $campaign->email_list_id)
            ->where('status', SubscriberStatus::Confirmed);

        // ar_apply_to: 'new' = only members who joined after activation;
        // 'existing' = pre-existing members too (F-2.3.2-016).
        if (($campaign->ar_apply_to ?? 'new') === 'new' && $campaign->send_started_at !== null) {
            $query->where(function ($q) use ($campaign): void {
                $q->where(function ($sub) use ($campaign): void {
                    $sub->whereNotNull('subscribed_at')->where('subscribed_at', '>=', $campaign->send_started_at);
                })->orWhere(function ($sub) use ($campaign): void {
                    $sub->whereNull('subscribed_at')->where('created_at', '>=', $campaign->send_started_at);
                });
            });
        }

        return $query->get()->map(fn (Subscriber $subscriber): array => [
            'subscriber' => $subscriber,
            'event' => Carbon::parse($subscriber->confirmed_at ?? $subscriber->subscribed_at ?? $subscriber->created_at),
            'occurrence' => 'subscribe',
        ])->values();
    }

    /** @return Collection<int, array{subscriber: Subscriber, event: Carbon, occurrence: string}> */
    private function afterUnsubscribe(Campaign $campaign): Collection
    {
        return Subscriber::withoutTenancy()
            ->where('list_id', $campaign->email_list_id)
            ->where('status', SubscriberStatus::Unsubscribed)
            ->whereNotNull('unsubscribed_at')
            ->get()
            ->map(fn (Subscriber $subscriber): array => [
                'subscriber' => $subscriber,
                'event' => Carbon::parse($subscriber->unsubscribed_at),
                'occurrence' => 'unsubscribe',
            ])->values();
    }

    /**
     * Yearly date-field trigger (birthdays/anniversaries): this year's
     * occurrence of the stored date, evaluated in the campaign timezone.
     * Only occurrences within the last 2 days fire — activating a campaign
     * never floods long-past anniversaries.
     *
     * @return Collection<int, array{subscriber: Subscriber, event: Carbon, occurrence: string}>
     */
    private function onDateField(Campaign $campaign, Carbon $now): Collection
    {
        $field = $campaign->ar_date_field_id !== null
            ? ListField::withoutTenancy()->find($campaign->ar_date_field_id)
            : null;

        if ($field === null) {
            return collect();
        }

        $timezone = $campaign->timezone ?: 'UTC';

        return Subscriber::withoutTenancy()
            ->where('list_id', $campaign->email_list_id)
            ->where('status', SubscriberStatus::Confirmed)
            ->whereHas('fieldValues', function ($q) use ($field): void {
                $q->withoutGlobalScopes()->where('list_field_id', $field->id)->whereNotNull('value');
            })
            ->with(['fieldValues' => fn ($q) => $q->withoutGlobalScopes()->where('list_field_id', $field->id)])
            ->get()
            ->map(function (Subscriber $subscriber) use ($now, $timezone): ?array {
                $raw = $subscriber->fieldValues->first()?->value;

                try {
                    $date = Carbon::parse((string) $raw);
                } catch (Throwable) {
                    return null;
                }

                $day = $date->day;

                if ($date->month === 2 && $day === 29 && ! Carbon::create($now->year, 1, 1)->isLeapYear()) {
                    $day = 28; // Feb-29 anniversaries celebrate on the 28th
                }

                $occurrence = Carbon::create($now->year, $date->month, $day, 0, 0, 0, $timezone);

                if ($occurrence->greaterThan($now) || $occurrence->diffInDays($now) > 2) {
                    return null; // not (yet / anymore) this year's occurrence
                }

                return [
                    'subscriber' => $subscriber,
                    'event' => $occurrence->copy()->utc(),
                    'occurrence' => 'date-'.$now->year,
                ];
            })
            ->filter()
            ->values();
    }

    /** @return Collection<int, array{subscriber: Subscriber, event: Carbon, occurrence: string}> */
    private function afterProfileUpdate(Campaign $campaign): Collection
    {
        return Subscriber::withoutTenancy()
            ->where('list_id', $campaign->email_list_id)
            ->where('status', SubscriberStatus::Confirmed)
            ->whereColumn('updated_at', '>', 'created_at')
            ->get()
            ->map(fn (Subscriber $subscriber): array => [
                'subscriber' => $subscriber,
                'event' => Carbon::parse($subscriber->updated_at),
                'occurrence' => 'profile-'.Carbon::parse($subscriber->updated_at)->getTimestamp(),
            ])->values();
    }

    /**
     * after-campaign-open / after-campaign-sent: events of the referenced
     * campaign (track opens / delivery logs), earliest per subscriber.
     *
     * @return Collection<int, array{subscriber: Subscriber, event: Carbon, occurrence: string}>
     */
    private function afterCampaignEvent(Campaign $campaign, string $kind): Collection
    {
        $trigger = $campaign->ar_trigger_campaign_id !== null
            ? Campaign::withoutTenancy()->withTrashed()->find($campaign->ar_trigger_campaign_id)
            : null;

        if ($trigger === null) {
            return collect();
        }

        if ($kind === 'open') {
            $events = CampaignTrackOpen::query()
                ->where('campaign_uid', $trigger->uid)
                ->selectRaw('subscriber_uid, min(created_at) as event_at')
                ->groupBy('subscriber_uid')
                ->get();

            $bySubscriberUid = $events->pluck('event_at', 'subscriber_uid');

            $subscribers = Subscriber::withoutTenancy()
                ->where('list_id', $campaign->email_list_id)
                ->where('status', SubscriberStatus::Confirmed)
                ->whereIn('uid', $bySubscriberUid->keys())
                ->get();

            return $subscribers->map(fn (Subscriber $subscriber): array => [
                'subscriber' => $subscriber,
                'event' => Carbon::parse((string) $bySubscriberUid[$subscriber->uid]),
                'occurrence' => 'open-'.$trigger->uid,
            ])->values();
        }

        $logs = CampaignDeliveryLog::query()
            ->where('campaign_id', $trigger->id)
            ->where('status', CampaignDeliveryLog::STATUS_SENT)
            ->get(['subscriber_id', 'created_at']);

        $byId = $logs->pluck('created_at', 'subscriber_id');

        $subscribers = Subscriber::withoutTenancy()
            ->where('list_id', $campaign->email_list_id)
            ->where('status', SubscriberStatus::Confirmed)
            ->whereIn('id', $byId->keys())
            ->get();

        return $subscribers->map(fn (Subscriber $subscriber): array => [
            'subscriber' => $subscriber,
            'event' => Carbon::parse((string) $byId[$subscriber->id]),
            'occurrence' => 'sent-'.$trigger->uid,
        ])->values();
    }

    /* ---------------------------------------------------------------- *
     *  Due-time + once-per-subscriber machinery.
     * ---------------------------------------------------------------- */

    /** trigger event + ar_delay, then ar_send_time in the campaign TZ. */
    private function dueAt(Campaign $campaign, Carbon $event): Carbon
    {
        $due = $event->copy();

        if ($campaign->ar_delay_value !== null && $campaign->ar_delay_unit !== null) {
            $due = $campaign->ar_delay_unit->addTo($due, (int) $campaign->ar_delay_value);
        }

        $sendTime = trim((string) $campaign->ar_send_time);

        if ($sendTime !== '') {
            $timezone = $campaign->timezone ?: 'UTC';
            $local = $due->copy()->setTimezone($timezone);
            $candidate = $local->copy()->setTimeFromTimeString($sendTime);

            if ($candidate->lessThan($local)) {
                $candidate->addDay(); // preferred hour already passed that day
            }

            $due = $candidate->utc();
        }

        return $due;
    }

    /**
     * The delivery log is the once-per-subscriber memory (F-2.3.2-017).
     * once ON: any existing row (or in-flight job key) blocks forever.
     * once OFF: a log row belonging to an OLDER occurrence (created before
     * the current occurrence moment) is retired so the unique constraint
     * admits the new send; a row from this occurrence blocks it.
     */
    private function clearForDispatch(Campaign $campaign, Subscriber $subscriber, string $occurrence, Carbon $event): bool
    {
        $key = 'send:'.$campaign->uid.':'.$subscriber->uid
            .($campaign->ar_once_per_subscriber ? '' : ':'.$occurrence);

        // In-flight or already-executed job (async pickup between ticks).
        if (DB::table('job_idempotency_keys')->where('key', $key)->exists()) {
            return false;
        }

        $existing = CampaignDeliveryLog::query()
            ->where('campaign_id', $campaign->id)
            ->where('subscriber_id', $subscriber->id)
            ->first();

        if ($existing === null) {
            return true;
        }

        if ($campaign->ar_once_per_subscriber) {
            return false;
        }

        if ($existing->status === CampaignDeliveryLog::STATUS_RETRYING) {
            return false; // in flight
        }

        if (Carbon::parse($existing->created_at)->greaterThanOrEqualTo($event)) {
            return false; // this occurrence was already served
        }

        $existing->delete();

        return true;
    }

    private function batchLimit(): int
    {
        try {
            return max(1, SettingsReader::cron()->subscribersPerBatch());
        } catch (Throwable) {
            return 1000;
        }
    }
}
