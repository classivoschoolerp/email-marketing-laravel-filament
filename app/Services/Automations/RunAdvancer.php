<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationRunStatus;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunLog;
use App\Models\Subscriber;
use App\Services\Automations\Executors\GoalExecutor;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Advances one run through the published graph (spec §2.4.2): execute the
 * current node → log → follow the branch, until the run sleeps, waits,
 * ends, or hits the per-wave step limit (loop guard for jump nodes — the
 * run then yields and the next tick resumes it).
 *
 * "Exit journey when goal met" (F-2.4.2-039): at the start of every wave
 * all continuously-evaluated goal nodes are checked, so a subscriber who
 * converts mid-delay exits without walking the remaining flow.
 */
class RunAdvancer
{
    public function __construct(
        private readonly ExecutorRegistry $executors,
        private readonly ConditionEvaluator $conditions,
    ) {}

    public function advance(AutomationRun $run): void
    {
        if (! $run->isActive()) {
            return;
        }

        /** @var Automation|null $automation */
        $automation = $run->automation()->first();

        if ($automation === null || ! $automation->isActive()) {
            return; // pause blocks advancement; tick re-dispatches on resume
        }

        /** @var Subscriber|null $subscriber */
        $subscriber = $run->subscriber()->withTrashed()->first();

        if ($subscriber === null || $subscriber->trashed()) {
            $this->finish($run, $automation, AutomationRunStatus::Exited, 'subscriber_removed');

            return;
        }

        $graph = Graph::for($automation);
        $ctx = new ExecutionContext($automation, $graph, $subscriber, $run);

        if ($this->goalShortCircuit($run, $automation, $graph, $ctx)) {
            return;
        }

        $maxSteps = max(1, (int) config('emp-automations.max_steps_per_advance', 25));
        $steps = 0;

        while (true) {
            $node = $graph->byId($run->current_node_id !== null ? (int) $run->current_node_id : null);

            if ($node === null) {
                $this->finish($run, $automation, AutomationRunStatus::Completed, 'end_of_flow');

                return;
            }

            try {
                $result = $this->executors->for($node->type)->execute($node, $ctx);
            } catch (Throwable $exception) {
                $this->log($run, $node, AutomationRunLog::ACTION_ERROR, ['error' => mb_substr($exception->getMessage(), 0, 500)]);
                $this->finish($run, $automation, AutomationRunStatus::Failed, 'node_exception');

                return;
            }

            switch ($result->kind) {
                case NodeResult::CONTINUE:
                    $this->log($run, $node, AutomationRunLog::ACTION_EXECUTED, $result->detail + ['branch' => $result->branch]);

                    $targetUid = $node->edge($result->branch);
                    $run->resume_branch = null;

                    if ($targetUid === null) {
                        $this->finish($run, $automation, AutomationRunStatus::Completed, 'end_of_flow');

                        return;
                    }

                    $target = $graph->byUid($targetUid);

                    if ($target === null) {
                        $this->finish($run, $automation, AutomationRunStatus::Failed, 'missing_node');

                        return;
                    }

                    $this->moveTo($run, $target);
                    break;

                case NodeResult::JUMP:
                    $target = $graph->byUid($result->targetUid);

                    if ($target === null) {
                        $this->finish($run, $automation, AutomationRunStatus::Failed, 'missing_node');

                        return;
                    }

                    $this->log($run, $node, AutomationRunLog::ACTION_BRANCH, ['jump_to' => $result->targetUid]);
                    $this->moveTo($run, $target);
                    break;

                case NodeResult::SLEEP:
                case NodeResult::WAIT:
                    $run->forceFill([
                        'wake_at' => $result->until,
                        'waiting_for' => $result->waitingFor,
                        'resume_branch' => null,
                    ])->save();
                    $this->log($run, $node, AutomationRunLog::ACTION_EXECUTED, $result->detail + [
                        'parked' => $result->kind,
                        'wake_at' => $result->until?->toIso8601String(),
                        'waiting_for' => $result->waitingFor,
                    ]);

                    return;

                case NodeResult::COMPLETE:
                    if (($result->detail['goal'] ?? false) === true) {
                        $this->recordConversion($run, $automation, $node, (int) ($result->detail['conversion_value'] ?? 0));
                    }

                    $this->finish($run, $automation, AutomationRunStatus::Completed, (string) $result->reason);

                    return;

                case NodeResult::EXIT:
                    $this->log($run, $node, AutomationRunLog::ACTION_EXECUTED, $result->detail);
                    $this->finish($run, $automation, AutomationRunStatus::Exited, (string) $result->reason);

                    return;

                case NodeResult::FAIL:
                default:
                    $this->log($run, $node, AutomationRunLog::ACTION_ERROR, ['error' => (string) $result->error]);
                    $this->finish($run, $automation, AutomationRunStatus::Failed, 'node_error');

                    return;
            }

            if (++$steps >= $maxSteps) {
                $run->forceFill(['wake_at' => Carbon::now()->addMinute()])->save();
                $this->log($run, $graph->byId((int) $run->current_node_id), AutomationRunLog::ACTION_EXECUTED, ['yielded' => true]);

                return;
            }
        }
    }

    /** True when a goal node converted + completed the run. */
    private function goalShortCircuit(AutomationRun $run, Automation $automation, Graph $graph, ExecutionContext $ctx): bool
    {
        foreach ($graph->goalNodes() as $goal) {
            if ((bool) $goal->config('continuous', true) === false) {
                continue;
            }

            // Don't double-run the goal node the run is standing on.
            if ((int) $goal->id === (int) $run->current_node_id && $run->waiting_for !== GoalExecutor::WAITING_KEY) {
                continue;
            }

            $met = $this->conditions->matches(
                $ctx->subscriber,
                (string) $goal->config('match', 'all'),
                array_values((array) $goal->config('conditions', [])),
            );

            if ($met) {
                $this->recordConversion($run, $automation, $goal, (int) $goal->config('conversion_value', 0));
                $this->finish($run, $automation, AutomationRunStatus::Completed, 'goal_met');

                return true;
            }
        }

        return false;
    }

    private function recordConversion(AutomationRun $run, Automation $automation, AutomationNode $goal, int $value): void
    {
        if ($run->goal_met_at !== null) {
            return;
        }

        $run->forceFill([
            'goal_met_at' => Carbon::now(),
            'conversion_value' => max(0, $value),
        ])->save();

        Automation::withoutTenancy()->whereKey($automation->id)->increment('converted_count');

        $this->log($run, $goal, AutomationRunLog::ACTION_GOAL_MET, ['conversion_value' => max(0, $value)]);
    }

    private function moveTo(AutomationRun $run, AutomationNode $node): void
    {
        $run->forceFill([
            'current_node_id' => $node->id,
            'node_entered_at' => Carbon::now(),
            'wake_at' => null,
            'waiting_for' => null,
        ])->save();

        $node->newQuery()->whereKey($node->id)->increment('entered_count');

        $this->log($run, $node, AutomationRunLog::ACTION_REACHED);
    }

    private function finish(AutomationRun $run, Automation $automation, AutomationRunStatus $status, string $reason): void
    {
        $run->forceFill([
            'status' => $status,
            'completed_at' => Carbon::now(),
            'wake_at' => null,
            'waiting_for' => null,
            'resume_branch' => null,
            'exit_reason' => $reason,
        ])->save();

        Automation::withoutTenancy()->whereKey($automation->id)
            ->where('active_count', '>', 0)->decrement('active_count');

        if ($status === AutomationRunStatus::Completed) {
            Automation::withoutTenancy()->whereKey($automation->id)->increment('completed_count');
        }

        $action = match ($status) {
            AutomationRunStatus::Completed => AutomationRunLog::ACTION_COMPLETED,
            AutomationRunStatus::Failed => AutomationRunLog::ACTION_FAILED,
            default => AutomationRunLog::ACTION_EXITED,
        };

        $this->log($run, null, $action, ['reason' => $reason]);
    }

    /** @param array<string, mixed> $detail */
    private function log(AutomationRun $run, ?AutomationNode $node, string $action, array $detail = []): void
    {
        AutomationRunLog::query()->create([
            'automation_id' => $run->automation_id,
            'run_id' => $run->id,
            'node_id' => $node?->id,
            'node_uid' => $node?->uid,
            'node_type' => $node?->type?->value,
            'action' => $action,
            'detail' => $detail === [] ? null : $detail,
            'created_at' => Carbon::now(),
        ]);
    }
}
