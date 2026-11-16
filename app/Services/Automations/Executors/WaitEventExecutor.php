<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\AutomationNode;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;
use Illuminate\Support\Carbon;

/**
 * F-2.4.2-035 — wait for event with a timeout branch. First arrival parks
 * the run on the event with the timeout alarm; ProcessAutomationEventJob
 * wakes matching runs with resume_branch = 'event', the tick wakes overdue
 * ones (branch 'timeout').
 */
class WaitEventExecutor implements NodeExecutor
{
    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        if ($ctx->dryRun) {
            return NodeResult::continue('event', ['assumed' => 'event']);
        }

        if ($ctx->run->resume_branch === 'event') {
            return NodeResult::continue('event');
        }

        $entered = Carbon::parse($ctx->run->node_entered_at ?? $ctx->now());
        $timeoutAt = DelayExecutor::addDuration(
            $entered,
            max(1, (int) $node->config('timeout_value', 1)),
            (string) $node->config('timeout_unit', 'days'),
        );

        if ($ctx->now()->greaterThanOrEqualTo($timeoutAt)) {
            return NodeResult::continue('timeout');
        }

        return NodeResult::wait((string) $node->config('event', ''), $timeoutAt);
    }
}
