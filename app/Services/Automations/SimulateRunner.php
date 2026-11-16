<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationRunStatus;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\Subscriber;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * F-2.4.2-003 — test/simulate run: walks a sample subscriber through the
 * published graph in dry-run mode. Conditions and splits evaluate for real
 * against the subscriber; side effects (emails, tags, webhooks, list ops)
 * are reported as would-do steps; delays/waits are skipped. Nothing is
 * persisted.
 */
class SimulateRunner
{
    public function __construct(private readonly ExecutorRegistry $executors) {}

    /**
     * @return list<array{uid: string, type: string, label: ?string, result: string, branch: ?string, detail: array<string, mixed>}>
     */
    public function simulate(Automation $automation, Subscriber $subscriber): array
    {
        return $this->simulateGraph($automation, Graph::for($automation), $subscriber);
    }

    /**
     * @return list<array{uid: string, type: string, label: ?string, result: string, branch: ?string, detail: array<string, mixed>}>
     */
    public function simulateGraph(Automation $automation, Graph $graph, Subscriber $subscriber): array
    {
        $trigger = $graph->trigger();

        if ($trigger === null) {
            return [];
        }

        $now = Carbon::now();

        $run = new AutomationRun([
            'status' => AutomationRunStatus::Active,
            'entered_at' => $now,
            'node_entered_at' => $now,
        ]);
        $run->setRelation('automation', $automation);

        $ctx = new ExecutionContext($automation, $graph, $subscriber, $run, dryRun: true, now: $now);

        $steps = [];
        $maxSteps = max(1, (int) config('emp-automations.simulate_max_steps', 50));
        $node = $trigger;

        while ($node !== null && count($steps) < $maxSteps) {
            try {
                $result = $this->executors->for($node->type)->execute($node, $ctx);
            } catch (Throwable $exception) {
                $steps[] = $this->step($node, 'error', null, ['error' => $exception->getMessage()]);

                return $steps;
            }

            switch ($result->kind) {
                case NodeResult::CONTINUE:
                    $steps[] = $this->step($node, 'executed', $result->branch, $result->detail);
                    $node = $graph->byUid($node->edge($result->branch));

                    if ($node === null) {
                        $steps[] = ['uid' => '', 'type' => '_end', 'label' => null, 'result' => 'completed', 'branch' => null, 'detail' => ['reason' => 'end_of_flow']];
                    }
                    break;

                case NodeResult::JUMP:
                    $steps[] = $this->step($node, 'jump', null, ['target' => $result->targetUid]);
                    $node = $graph->byUid($result->targetUid);
                    break;

                case NodeResult::COMPLETE:
                    $steps[] = $this->step($node, 'completed', null, $result->detail + ['reason' => $result->reason]);

                    return $steps;

                case NodeResult::EXIT:
                    $steps[] = $this->step($node, 'exited', null, $result->detail + ['reason' => $result->reason]);

                    return $steps;

                case NodeResult::SLEEP:
                case NodeResult::WAIT:
                    // Dry-run executors don't park, but keep the guard anyway.
                    $steps[] = $this->step($node, 'parked', null, $result->detail);
                    $node = $graph->byUid($node->edge('next'));
                    break;

                case NodeResult::FAIL:
                default:
                    $steps[] = $this->step($node, 'error', null, ['error' => $result->error]);

                    return $steps;
            }
        }

        if (count($steps) >= $maxSteps) {
            $steps[] = ['uid' => '', 'type' => '_end', 'label' => null, 'result' => 'step_limit', 'branch' => null, 'detail' => []];
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{uid: string, type: string, label: ?string, result: string, branch: ?string, detail: array<string, mixed>}
     */
    private function step(AutomationNode $node, string $result, ?string $branch, array $detail = []): array
    {
        return [
            'uid' => (string) $node->uid,
            'type' => $node->type->value,
            'label' => $node->label,
            'result' => $result,
            'branch' => $branch,
            'detail' => $detail,
        ];
    }
}
