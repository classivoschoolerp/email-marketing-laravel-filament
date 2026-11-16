<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\AutomationNode;
use App\Services\Automations\ConditionEvaluator;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;

/**
 * F-2.4.2-039 — goal node: when the condition set matches, the journey
 * exits as CONVERTED (status completed, goal_met_at stamped, the node's
 * configured conversion_value in minor units feeds "revenue attributed",
 * F-2.4.3-004). Not met: with wait=true the run parks and re-checks every
 * goal_recheck_minutes (events that change the subscriber also wake it via
 * the advancer's wave-start goal check); with wait=false the flow continues
 * on `next`.
 */
class GoalExecutor implements NodeExecutor
{
    public const WAITING_KEY = '_goal';

    public function __construct(private readonly ConditionEvaluator $evaluator) {}

    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        $met = $this->evaluator->matches(
            $ctx->subscriber,
            (string) $node->config('match', 'all'),
            array_values((array) $node->config('conditions', [])),
        );

        if ($met) {
            return NodeResult::complete('goal_met', [
                'goal' => true,
                'conversion_value' => (int) $node->config('conversion_value', 0),
            ]);
        }

        if (! (bool) $node->config('wait', false) || $ctx->dryRun) {
            return NodeResult::continue('next', ['goal' => false]);
        }

        return NodeResult::sleep(
            $ctx->now()->addMinutes(max(1, (int) config('emp-automations.goal_recheck_minutes', 60))),
            self::WAITING_KEY,
            ['goal' => false],
        );
    }
}
