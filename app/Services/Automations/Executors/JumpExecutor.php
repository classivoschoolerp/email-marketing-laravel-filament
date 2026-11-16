<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\Automation;
use App\Models\AutomationNode;
use App\Services\Automations\Enroller;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;

/**
 * F-2.4.2-041 — jump to another node (loop-guarded by the advancer's step
 * limit) or to another automation (enrolls there — its re-entry rules
 * apply — and exits the current journey with reason `jumped`).
 */
class JumpExecutor implements NodeExecutor
{
    public function __construct(private readonly Enroller $enroller) {}

    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        if ((string) $node->config('target', 'node') === 'automation') {
            $target = Automation::withoutTenancy()
                ->where('customer_id', $ctx->automation->customer_id)
                ->find((int) $node->config('target_automation_id', 0));

            if ($target === null) {
                return NodeResult::fail(__('automations.errors.jump_target_missing'));
            }

            if (! $ctx->dryRun) {
                $this->enroller->enter($target, $ctx->subscriber, [], 'jump');
            }

            return NodeResult::exit('jumped', ['target_automation_id' => $target->id]);
        }

        $targetUid = (string) $node->config('target_node_uid', '');

        if ($ctx->graph->byUid($targetUid) === null) {
            return NodeResult::fail(__('automations.errors.jump_target_missing'));
        }

        return NodeResult::jump($targetUid);
    }
}
