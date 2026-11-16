<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\AutomationNode;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;

/**
 * One implementation per §2.4.2 node type. Executors MUST be re-entrant:
 * a sleeping/waiting node is executed again on wake and decides from the
 * run's node_entered_at / resume_branch what to do next. In dry-run mode
 * (ctx->dryRun) no side effects may occur.
 */
interface NodeExecutor
{
    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult;
}
