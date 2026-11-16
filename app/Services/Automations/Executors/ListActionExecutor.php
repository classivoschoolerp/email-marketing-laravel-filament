<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Enums\SubscriberStatus;
use App\Models\AutomationNode;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;
use App\Services\Lists\SubscriberCloner;

/**
 * F-2.4.2-027 — add to / remove from / move to list.
 * - add: clone the subscriber into the target list (SubscriberCloner; the
 *   clone's created event fires the target list's cascades + triggers).
 * - remove: soft-delete the subscriber's row on the target list (matched by
 *   email).
 * - move: clone to target, then flip the source row to Moved through the
 *   model so WP-06 cascades fire (BulkSubscriberActionJob semantics).
 */
class ListActionExecutor implements NodeExecutor
{
    public function __construct(private readonly SubscriberCloner $cloner) {}

    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        $op = (string) $node->config('op', 'add');

        $target = EmailList::withoutTenancy()
            ->where('customer_id', $ctx->automation->customer_id)
            ->find((int) $node->config('list_id', 0));

        if ($target === null) {
            return NodeResult::fail(__('automations.errors.list_missing'));
        }

        if ($ctx->dryRun) {
            return NodeResult::continue(detail: ['would_list_op' => $op, 'list_id' => $target->id]);
        }

        $detail = ['op' => $op, 'list_id' => $target->id];

        switch ($op) {
            case 'remove':
                $row = Subscriber::withoutTenancy()
                    ->where('list_id', $target->id)
                    ->where('email', $ctx->subscriber->email)
                    ->first();

                if ($row !== null) {
                    $row->delete();
                    $detail['removed'] = true;
                }
                break;

            case 'move':
                $clone = $this->cloner->cloneTo($ctx->subscriber, $target);
                $detail['cloned'] = $clone !== null;

                if ((int) $ctx->subscriber->list_id !== (int) $target->id) {
                    $ctx->subscriber->status = SubscriberStatus::Moved;
                    $ctx->subscriber->save();
                    $detail['moved'] = true;
                }
                break;

            default: // add
                $clone = $this->cloner->cloneTo($ctx->subscriber, $target);
                $detail['cloned'] = $clone !== null;
                break;
        }

        return NodeResult::continue(detail: $detail);
    }
}
