<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\AutomationNode;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;

/**
 * F-2.4.2-038 — percentage split (A/B/… within the journey). The bucket is
 * a deterministic hash of (automation uid, node uid, run) so re-executions
 * of the same run always take the same branch (idempotent by construction —
 * same D-55 spirit as spintax seeds).
 */
class PercentageExecutor implements NodeExecutor
{
    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        $variants = array_values((array) $node->config('variants', []));

        if (count($variants) < 2) {
            return NodeResult::continue('a', ['bucket' => 0]);
        }

        $seed = $ctx->automation->uid.':'.$node->uid.':'.($ctx->run->id ?? $ctx->subscriber->uid);
        $bucket = (int) (hexdec(substr(hash('xxh128', $seed), 0, 8)) % 100);

        $cumulative = 0;

        foreach ($variants as $index => $variant) {
            $cumulative += (int) ($variant['percent'] ?? 0);

            if ($bucket < $cumulative) {
                return NodeResult::continue(chr(ord('a') + $index), ['bucket' => $bucket]);
            }
        }

        return NodeResult::continue(chr(ord('a') + count($variants) - 1), ['bucket' => $bucket]);
    }
}
