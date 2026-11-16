<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Enums\AutomationNodeType;
use App\Models\AutomationNode;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;
use App\Services\Subscribers\SubscriberTagService;

/**
 * F-2.4.2-025 — add / remove tag. Goes through SubscriberTagService so the
 * tag model events feed other automations' tag triggers (documented: an
 * automation's tag action can trigger another automation; self-triggering
 * is bounded by re-entry rules).
 */
class TagExecutor implements NodeExecutor
{
    public function __construct(private readonly SubscriberTagService $tags) {}

    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        $tag = trim((string) $node->config('tag', ''));

        if ($tag === '') {
            return NodeResult::fail(__('automations.errors.no_tag'));
        }

        $add = $node->type === AutomationNodeType::ActionAddTag;

        if ($ctx->dryRun) {
            return NodeResult::continue(detail: [($add ? 'would_add_tag' : 'would_remove_tag') => $tag]);
        }

        if ($add) {
            $this->tags->attach($ctx->subscriber, $tag);
        } else {
            $this->tags->detach($ctx->subscriber, $tag);
        }

        return NodeResult::continue(detail: ['tag' => $tag, 'op' => $add ? 'add' : 'remove']);
    }
}
