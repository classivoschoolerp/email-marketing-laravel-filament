<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationNodeType;
use App\Enums\SubscriberStatus;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationSegmentMember;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackOpen;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Segments\SegmentQueryBuilder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Tick-side evaluation for triggers that have no push event (spec §2.4.2):
 * - trigger.date (F-2.4.2-013): birthday/anniversary (annual) or exact
 *   custom date field ± offset days, WP-13 autoresponder date maths — only
 *   occurrences within the config window (2 days) fire so activation never
 *   floods long-past dates. Yearly re-fires require re-entry `multiple` /
 *   `cooldown` (documented).
 * - trigger.campaign_not_opened (F-2.4.2-010): delivered ≥ N days ago with
 *   no open row; inherently once per subscriber (any previous run blocks,
 *   regardless of re-entry mode).
 * - trigger.segment_entered / exited (F-2.4.2-018): diff current dynamic
 *   matches against the automation_segment_members snapshot.
 *
 * Every pass is bounded by emp-automations.poll_enroll_limit.
 */
class PollingTriggers
{
    public function __construct(
        private readonly Enroller $enroller,
        private readonly SegmentQueryBuilder $segments,
        private readonly GraphPublisher $publisher,
    ) {}

    /** @return int enrollments created */
    public function evaluate(Automation $automation): int
    {
        try {
            return match ($automation->triggerType()) {
                AutomationNodeType::TriggerDate => $this->dateTrigger($automation),
                AutomationNodeType::TriggerCampaignNotOpened => $this->notOpenedTrigger($automation),
                AutomationNodeType::TriggerSegmentEntered => $this->segmentDiff($automation, enrollOnEnter: true),
                AutomationNodeType::TriggerSegmentExited => $this->segmentDiff($automation, enrollOnEnter: false),
                default => 0,
            };
        } catch (Throwable) {
            return 0; // one broken automation must not stall the tick
        }
    }

    private function dateTrigger(Automation $automation): int
    {
        $config = (array) ($automation->trigger_config ?? []);
        $field = ListField::withoutTenancy()->find((int) ($config['list_field_id'] ?? 0));

        if ($field === null) {
            return 0;
        }

        $annual = (bool) ($config['annual'] ?? true);
        $offsetDays = (int) ($config['offset_days'] ?? 0);
        $windowDays = max(1, (int) config('emp-automations.date_trigger_window_days', 2));
        $limit = $this->limit();
        $now = Carbon::now();
        $enrolled = 0;

        $subscribers = Subscriber::withoutTenancy()
            ->where('list_id', $field->list_id)
            ->where('status', SubscriberStatus::Confirmed)
            ->whereHas('fieldValues', function ($q) use ($field): void {
                $q->withoutGlobalScopes()->where('list_field_id', $field->id)->whereNotNull('value');
            })
            ->with(['fieldValues' => fn ($q) => $q->withoutGlobalScopes()->where('list_field_id', $field->id)])
            ->get();

        foreach ($subscribers as $subscriber) {
            if ($enrolled >= $limit) {
                break;
            }

            $raw = (string) ($subscriber->fieldValues->first()?->value ?? '');

            try {
                $date = Carbon::parse($raw);
            } catch (Throwable) {
                continue;
            }

            if ($annual) {
                $day = $date->day;

                if ($date->month === 2 && $day === 29 && ! Carbon::create($now->year, 1, 1)->isLeapYear()) {
                    $day = 28;
                }

                $occurrence = Carbon::create($now->year, $date->month, $day, 0, 0, 0);
            } else {
                $occurrence = $date->copy()->startOfDay();
            }

            $due = $occurrence->copy()->addDays($offsetDays);

            if ($due->greaterThan($now) || $due->diffInDays($now) > $windowDays) {
                continue;
            }

            // Once per occurrence: a run entered at/after the due moment
            // means this occurrence was already served.
            $served = AutomationRun::withoutTenancy()
                ->where('automation_id', $automation->id)
                ->where('subscriber_id', $subscriber->id)
                ->where('entered_at', '>=', $due)
                ->exists();

            if ($served) {
                continue;
            }

            if ($this->enroller->enter($automation, $subscriber, ['occurrence' => $due->toDateString()], 'date') !== null) {
                $enrolled++;
            }
        }

        return $enrolled;
    }

    private function notOpenedTrigger(Automation $automation): int
    {
        $config = (array) ($automation->trigger_config ?? []);
        $campaign = Campaign::withoutTenancy()->withTrashed()->find((int) ($config['campaign_id'] ?? 0));

        if ($campaign === null) {
            return 0;
        }

        $days = max(1, (int) ($config['days'] ?? 1));
        $cutoff = Carbon::now()->subDays($days);
        $limit = $this->limit();
        $enrolled = 0;

        $openerUids = CampaignTrackOpen::query()
            ->where('campaign_uid', $campaign->uid)
            ->distinct()
            ->pluck('subscriber_uid');

        $subscriberIds = CampaignDeliveryLog::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', CampaignDeliveryLog::STATUS_SENT)
            ->where('created_at', '<=', $cutoff)
            ->pluck('subscriber_id');

        $subscribers = Subscriber::withoutTenancy()
            ->whereIn('id', $subscriberIds)
            ->where('status', SubscriberStatus::Confirmed)
            ->whereNotIn('uid', $openerUids)
            ->whereNotExists(function ($q) use ($automation): void {
                $q->selectRaw('1')
                    ->from('automation_runs')
                    ->whereColumn('automation_runs.subscriber_id', 'subscribers.id')
                    ->where('automation_runs.automation_id', $automation->id);
            })
            ->limit($limit)
            ->get();

        foreach ($subscribers as $subscriber) {
            if ($this->enroller->enter($automation, $subscriber, ['campaign_id' => $campaign->id], 'not_opened') !== null) {
                $enrolled++;
            }
        }

        return $enrolled;
    }

    private function segmentDiff(Automation $automation, bool $enrollOnEnter): int
    {
        $config = (array) ($automation->trigger_config ?? []);
        $segment = ListSegment::withoutTenancy()->find((int) ($config['segment_id'] ?? 0));

        if ($segment === null) {
            return 0;
        }

        if ($automation->segment_seeded_at === null) {
            $this->publisher->seedSegmentState($automation);

            return 0;
        }

        $current = $this->segments->subscribers($segment)
            ->withoutGlobalScopes()
            ->pluck('subscribers.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $stored = AutomationSegmentMember::query()
            ->where('automation_id', $automation->id)
            ->pluck('subscriber_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $entered = array_values(array_diff($current, $stored));
        $exited = array_values(array_diff($stored, $current));
        $limit = $this->limit();
        $now = Carbon::now();
        $enrolled = 0;

        foreach ($entered as $subscriberId) {
            AutomationSegmentMember::query()->firstOrCreate(
                ['automation_id' => $automation->id, 'subscriber_id' => $subscriberId],
                ['created_at' => $now],
            );
        }

        if ($exited !== []) {
            AutomationSegmentMember::query()
                ->where('automation_id', $automation->id)
                ->whereIn('subscriber_id', $exited)
                ->delete();
        }

        $toEnroll = $enrollOnEnter ? $entered : $exited;

        foreach ($toEnroll as $subscriberId) {
            if ($enrolled >= $limit) {
                break;
            }

            $subscriber = Subscriber::withoutTenancy()->find($subscriberId);

            if ($subscriber === null) {
                continue;
            }

            if ($this->enroller->enter($automation, $subscriber, [], $enrollOnEnter ? 'segment_entered' : 'segment_exited') !== null) {
                $enrolled++;
            }
        }

        return $enrolled;
    }

    private function limit(): int
    {
        return max(1, (int) config('emp-automations.poll_enroll_limit', 500));
    }
}
