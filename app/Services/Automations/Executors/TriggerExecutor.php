<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\AutomationNode;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;

/** Trigger nodes are entry markers — execution just moves on. */
class TriggerExecutor implements NodeExecutor
{
    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        return NodeResult::continue();
    }
}
