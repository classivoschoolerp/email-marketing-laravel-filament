<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationRunStatus;
use App\Jobs\Automations\AdvanceAutomationRunJob;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationRunLog;
use App\Models\Subscriber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enters a subscriber into an automation (WP-21). Enforces the F-2.4.2-043
 * re-entry rules — `once`: any previous run (any status) blocks forever;
 * `multiple`: only a concurrent active run blocks; `cooldown`: active run
 * blocks, otherwise the last entry must be older than the cooldown. An
 * active run always blocks (no concurrent duplicate journeys).
 */
class Enroller
{
    /** @param array<string, mixed> $payload */
    public function enter(Automation $automation, Subscriber $subscriber, array $payload = [], string $via = 'trigger'): ?AutomationRun
    {
        if (! $automation->isActive() || ! $automation->isPublished()) {
            return null;
        }

        if ((int) $subscriber->customer_id !== (int) $automation->customer_id || $subscriber->trashed()) {
            return null;
        }

        $graph = Graph::for($automation);
        $trigger = $graph->trigger();

        if ($trigger === null) {
            return null;
        }

        if (! $this->reentryAllows($automation, $subscriber)) {
            return null;
        }

        $now = Carbon::now();

        try {
            $run = DB::transaction(function () use ($automation, $subscriber, $trigger, $payload, $via, $now): AutomationRun {
                $entryNumber = 1 + (int) AutomationRun::withoutTenancy()
                    ->where('automation_id', $automation->id)
                    ->where('subscriber_id', $subscriber->id)
                    ->max('entry_number');

                $run = AutomationRun::withoutTenancy()->create([
                    'automation_id' => $automation->id,
                    'customer_id' => $automation->customer_id,
                    'subscriber_id' => $subscriber->id,
                    'current_node_id' => $trigger->id,
                    'status' => AutomationRunStatus::Active,
                    'entry_number' => $entryNumber,
                    'version' => $automation->version,
                    'wake_at' => $now,
                    'node_entered_at' => $now,
                    'entered_at' => $now,
                    'payload' => $payload === [] ? null : $payload,
                ]);

                Automation::withoutTenancy()->whereKey($automation->id)->increment('entered_count');
                Automation::withoutTenancy()->whereKey($automation->id)->increment('active_count');
                $trigger->newQuery()->whereKey($trigger->id)->increment('entered_count');

                AutomationRunLog::query()->create([
                    'automation_id' => $automation->id,
                    'run_id' => $run->id,
                    'node_id' => $trigger->id,
                    'node_uid' => $trigger->uid,
                    'node_type' => $trigger->type->value,
                    'action' => AutomationRunLog::ACTION_ENTERED,
                    'detail' => ['via' => $via],
                    'created_at' => $now,
                ]);

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // concurrent enrollment of the same entry
        }

        AdvanceAutomationRunJob::dispatch($run->id, (int) $trigger->id, $now->getTimestamp());

        return $run;
    }

    /**
     * Terminate a subscriber's active runs (cross-automation unsubscribe
     * action, F-2.4.2-029). Matches by email across the customer's lists.
     *
     * @return int runs exited
     */
    public function exitRunsForEmail(Automation $automation, string $email, string $reason): int
    {
        $subscriberIds = Subscriber::withoutTenancy()
            ->where('customer_id', $automation->customer_id)
            ->where('email', mb_strtolower(trim($email)))
            ->pluck('id');

        if ($subscriberIds->isEmpty()) {
            return 0;
        }

        $runs = AutomationRun::withoutTenancy()
            ->where('automation_id', $automation->id)
            ->whereIn('subscriber_id', $subscriberIds)
            ->where('status', AutomationRunStatus::Active->value)
            ->get();

        $now = Carbon::now();

        foreach ($runs as $run) {
            $run->forceFill([
                'status' => AutomationRunStatus::Exited,
                'completed_at' => $now,
                'wake_at' => null,
                'waiting_for' => null,
                'exit_reason' => $reason,
            ])->save();

            Automation::withoutTenancy()->whereKey($automation->id)
                ->where('active_count', '>', 0)->decrement('active_count');

            AutomationRunLog::query()->create([
                'automation_id' => $automation->id,
                'run_id' => $run->id,
                'node_id' => $run->current_node_id,
                'action' => AutomationRunLog::ACTION_EXITED,
                'detail' => ['reason' => $reason],
                'created_at' => $now,
            ]);
        }

        return $runs->count();
    }

    private function reentryAllows(Automation $automation, Subscriber $subscriber): bool
    {
        $query = AutomationRun::withoutTenancy()
            ->where('automation_id', $automation->id)
            ->where('subscriber_id', $subscriber->id);

        if ((clone $query)->where('status', AutomationRunStatus::Active->value)->exists()) {
            return false;
        }

        return match ($automation->reentry_mode) {
            Automation::REENTRY_MULTIPLE => true,
            Automation::REENTRY_COOLDOWN => ! (clone $query)
                ->where('entered_at', '>', Carbon::now()->subMinutes(max(1, (int) $automation->reentry_cooldown_minutes)))
                ->exists(),
            default => ! $query->exists(),
        };
    }
}
