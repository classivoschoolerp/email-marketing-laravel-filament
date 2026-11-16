<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\Automation;
use App\Models\AutomationNode;
use App\Services\Automations\Enroller;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;

/**
 * F-2.4.2-029 — subscribe the contact to / unsubscribe them from another
 * automation. Subscribe honors the target's re-entry rules; unsubscribe
 * exits the contact's active runs there (matched by email across lists).
 */
class CrossAutomationExecutor implements NodeExecutor
{
    public function __construct(private readonly Enroller $enroller) {}

    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        $target = Automation::withoutTenancy()
            ->where('customer_id', $ctx->automation->customer_id)
            ->find((int) $node->config('automation_id', 0));

        if ($target === null || (int) $target->id === (int) $ctx->automation->id) {
            return NodeResult::fail(__('automations.errors.automation_missing'));
        }

        $op = (string) $node->config('op', 'subscribe');

        if ($ctx->dryRun) {
            return NodeResult::continue(detail: ['would_'.$op => $target->id]);
        }

        if ($op === 'unsubscribe') {
            $exited = $this->enroller->exitRunsForEmail($target, (string) $ctx->subscriber->email, 'cross_automation');

            return NodeResult::continue(detail: ['unsubscribed' => $target->id, 'runs_exited' => $exited]);
        }

        $run = $this->enroller->enter($target, $ctx->subscriber, [], 'cross_automation');

        return NodeResult::continue(detail: ['subscribed' => $target->id, 'entered' => $run !== null]);
    }
}
