<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Jobs\Automations\AutomationSendJob;
use App\Models\AutomationNode;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;

/**
 * F-2.4.2-020 — send email (template or inline). The heavy lifting happens
 * in AutomationSendJob (Queues::AUTOMATION, idempotency key
 * automation-send:{run}:{node}); the flow continues immediately — the email
 * is fire-and-forget relative to the journey.
 */
class SendEmailExecutor implements NodeExecutor
{
    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        if ($ctx->dryRun) {
            return NodeResult::continue(detail: [
                'would_send' => true,
                'subject' => (string) $node->config('subject', ''),
                'mode' => (string) $node->config('mode', 'inline'),
            ]);
        }

        AutomationSendJob::dispatch((int) $ctx->run->id, (int) $node->id);

        return NodeResult::continue(detail: ['queued' => true]);
    }
}
