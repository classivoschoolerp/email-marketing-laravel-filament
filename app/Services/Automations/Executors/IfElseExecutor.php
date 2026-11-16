<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\AutomationNode;
use App\Services\Automations\ConditionEvaluator;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;

/**
 * F-2.4.2-036 — if/else branch over the §2.2.10 condition vocabulary
 * (fields, status, dates, campaign engagement) evaluated on the single
 * subscriber.
 */
class IfElseExecutor implements NodeExecutor
{
    public function __construct(private readonly ConditionEvaluator $evaluator) {}

    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        $matched = $this->evaluator->matches(
            $ctx->subscriber,
            (string) $node->config('match', 'all'),
            array_values((array) $node->config('conditions', [])),
        );

        return NodeResult::continue($matched ? 'yes' : 'no', ['matched' => $matched]);
    }
}
