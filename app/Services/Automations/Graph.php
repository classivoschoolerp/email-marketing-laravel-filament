<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationNodeType;
use App\Models\Automation;
use App\Models\AutomationNode;
use Illuminate\Support\Collection;

/**
 * In-memory view of a published automation graph (spec §2.4.2): uid/id maps
 * plus BFS traversal used by the advancer, the report funnel and the
 * simulator.
 */
final class Graph
{
    /** @var array<string, AutomationNode> */
    private array $byUid = [];

    /** @var array<int, AutomationNode> */
    private array $byId = [];

    /** @param Collection<int, AutomationNode> $nodes */
    private function __construct(Collection $nodes)
    {
        foreach ($nodes as $node) {
            $this->byUid[(string) $node->uid] = $node;
            $this->byId[(int) $node->id] = $node;
        }
    }

    public static function for(Automation $automation): self
    {
        return new self($automation->nodes()->get());
    }

    /**
     * Build from raw canvas node arrays (unsaved AutomationNode instances) —
     * used by the simulator so drafts can be tested before publishing.
     * Traversal is uid-based, so missing ids are fine.
     *
     * @param list<array<string, mixed>> $nodes
     */
    public static function fromCanvas(array $nodes): self
    {
        return new self(collect($nodes)->map(fn (array $node): AutomationNode => new AutomationNode([
            'uid' => (string) ($node['uid'] ?? ''),
            'type' => (string) ($node['type'] ?? ''),
            'label' => $node['label'] ?? null,
            'config' => (array) ($node['config'] ?? []),
            'edges' => (array) ($node['edges'] ?? []),
        ])));
    }

    public function isEmpty(): bool
    {
        return $this->byUid === [];
    }

    public function byUid(?string $uid): ?AutomationNode
    {
        return $uid !== null ? ($this->byUid[$uid] ?? null) : null;
    }

    public function byId(?int $id): ?AutomationNode
    {
        return $id !== null ? ($this->byId[$id] ?? null) : null;
    }

    public function trigger(): ?AutomationNode
    {
        foreach ($this->byUid as $node) {
            if ($node->type->isTrigger()) {
                return $node;
            }
        }

        return null;
    }

    /** @return list<AutomationNode> */
    public function goalNodes(): array
    {
        return array_values(array_filter(
            $this->byUid,
            fn (AutomationNode $node): bool => $node->type === AutomationNodeType::LogicGoal,
        ));
    }

    /**
     * Breadth-first order from the trigger — the funnel order (§2.4.3).
     *
     * @return list<AutomationNode>
     */
    public function bfsOrder(): array
    {
        $trigger = $this->trigger();

        if ($trigger === null) {
            return array_values($this->byUid);
        }

        $order = [];
        $seen = [];
        $queue = [$trigger];

        while ($queue !== []) {
            $node = array_shift($queue);

            if (isset($seen[(string) $node->uid])) {
                continue;
            }

            $seen[(string) $node->uid] = true;
            $order[] = $node;

            foreach ($node->branchKeys() as $branch) {
                $child = $this->byUid($node->edge($branch));

                if ($child !== null && ! isset($seen[(string) $child->uid])) {
                    $queue[] = $child;
                }
            }
        }

        // Unreachable leftovers (shouldn't exist after validation) go last.
        foreach ($this->byUid as $uid => $node) {
            if (! isset($seen[$uid])) {
                $order[] = $node;
            }
        }

        return $order;
    }

    /** @return list<AutomationNode> */
    public function all(): array
    {
        return array_values($this->byUid);
    }
}
