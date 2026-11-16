<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationNodeType;
use App\Models\AutomationNode;

/**
 * F-2.4.2-001 — canvas geometry for the §2.4.2 workflow builder.
 *
 * The canvas JSON gained two integer keys per node, `x` and `y` (pixels in
 * canvas space, top-left of the node card). Everything the browser does —
 * pointer drag, zoom, pan, minimap — reads and writes those two numbers, so
 * a laid-out journey survives a reload and is assertable server-side.
 *
 * tidy() is the auto-layout: a Reingold–Tilford style tidy tree walked from
 * the trigger in branch order. Leaves take the next free column, a parent
 * centres over the span of its own children, depth drives y. Columns are
 * counted in half-steps (leaf cursor += 2) so a parent's centre is always an
 * exact integer — no floats anywhere, matching the money/position rule of
 * docs/ARCHITECTURE.md. Cycles (jump-node loops) and shared children are placed once:
 * the second visit is skipped rather than followed, which keeps the walk
 * O(nodes). Nodes the trigger cannot reach are parked on a spare row under
 * the tree instead of being silently dropped.
 */
final class CanvasLayout
{
    /** Half a node column — leaves sit 2 units apart. */
    public const X_UNIT = 140;

    /** Vertical distance between depths. */
    public const Y_STEP = 170;

    /** Rendered node card size (blade + minimap share these). */
    public const NODE_WIDTH = 248;

    public const NODE_HEIGHT = 84;

    /**
     * Tidy-tree coordinates for every node.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, array{x: int, y: int}>
     */
    public function tidy(array $nodes): array
    {
        $byUid = [];

        foreach ($nodes as $node) {
            $uid = (string) ($node['uid'] ?? '');

            if ($uid !== '') {
                $byUid[$uid] = $node;
            }
        }

        if ($byUid === []) {
            return [];
        }

        $root = $this->rootUid($byUid);
        $cursor = 0;
        $positions = [];

        $place = function (string $uid, int $depth, array $path) use (&$place, &$cursor, &$positions, $byUid): int {
            $node = $byUid[$uid];
            $type = AutomationNodeType::tryFrom((string) ($node['type'] ?? ''));
            $childColumns = [];

            if ($type !== null) {
                foreach (AutomationNode::branchKeysFor($type, (array) ($node['config'] ?? [])) as $branch) {
                    $child = (string) (($node['edges'][$branch] ?? '') ?? '');

                    if ($child === '' || ! isset($byUid[$child])) {
                        continue;
                    }

                    // Already placed (shared child) or an ancestor (cycle).
                    if (isset($positions[$child]) || in_array($child, $path, true)) {
                        continue;
                    }

                    $childColumns[] = $place($child, $depth + 1, [...$path, $uid]);
                }
            }

            if ($childColumns === []) {
                $column = $cursor;
                $cursor += 2;
            } else {
                $column = intdiv(min($childColumns) + max($childColumns), 2);
            }

            $positions[$uid] = ['x' => $column * self::X_UNIT, 'y' => $depth * self::Y_STEP];

            return $column;
        };

        $place($root, 0, []);

        // Anything the trigger cannot reach keeps a deterministic home rather
        // than stacking at the origin.
        $orphanDepth = 1;

        foreach ($positions as $position) {
            $orphanDepth = max($orphanDepth, intdiv($position['y'], self::Y_STEP) + 1);
        }

        foreach (array_keys($byUid) as $uid) {
            if (isset($positions[$uid])) {
                continue;
            }

            $positions[$uid] = ['x' => $cursor * self::X_UNIT, 'y' => $orphanDepth * self::Y_STEP];
            $cursor += 2;
        }

        return $positions;
    }

    /**
     * Rewrite every node's x/y from tidy() (the auto-layout button).
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    public function applyTidy(array $nodes): array
    {
        $positions = $this->tidy($nodes);

        foreach ($nodes as $index => $node) {
            $uid = (string) ($node['uid'] ?? '');

            if (isset($positions[$uid])) {
                $nodes[$index]['x'] = $positions[$uid]['x'];
                $nodes[$index]['y'] = $positions[$uid]['y'];
            }
        }

        return array_values($nodes);
    }

    /**
     * Fill in coordinates only where they are missing or malformed — the
     * upgrade path for canvases authored before positions existed. Explicit
     * coordinates are never moved.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array{0: list<array<string, mixed>>, 1: bool} nodes + "changed"
     */
    public function ensurePositions(array $nodes): array
    {
        $missing = false;

        foreach ($nodes as $node) {
            if (! $this->hasPosition($node)) {
                $missing = true;
                break;
            }
        }

        if (! $missing) {
            return [array_values($nodes), false];
        }

        $positions = $this->tidy($nodes);

        foreach ($nodes as $index => $node) {
            if ($this->hasPosition($node)) {
                $nodes[$index]['x'] = (int) $node['x'];
                $nodes[$index]['y'] = (int) $node['y'];

                continue;
            }

            $uid = (string) ($node['uid'] ?? '');
            $nodes[$index]['x'] = $positions[$uid]['x'] ?? 0;
            $nodes[$index]['y'] = $positions[$uid]['y'] ?? 0;
        }

        return [array_values($nodes), true];
    }

    /**
     * A free slot under a parent for a freshly added node, so add-node never
     * drops a card on top of an existing one.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array{x: int, y: int}
     */
    public function slotUnder(array $nodes, string $parentUid): array
    {
        $parent = null;

        foreach ($nodes as $node) {
            if ((string) ($node['uid'] ?? '') === $parentUid) {
                $parent = $node;
                break;
            }
        }

        $x = $this->hasPosition($parent ?? []) ? (int) $parent['x'] : 0;
        $y = ($this->hasPosition($parent ?? []) ? (int) $parent['y'] : 0) + self::Y_STEP;

        $taken = true;

        while ($taken) {
            $taken = false;

            foreach ($nodes as $node) {
                if (! $this->hasPosition($node)) {
                    continue;
                }

                if (abs((int) $node['x'] - $x) < self::NODE_WIDTH && abs((int) $node['y'] - $y) < self::NODE_HEIGHT) {
                    $x += self::X_UNIT * 2;
                    $taken = true;
                    break;
                }
            }
        }

        return ['x' => $x, 'y' => $y];
    }

    /**
     * Render-ready edge list: one row per branch slot, whether or not it is
     * connected. Empty slots are the drop targets the builder draws a "+" on.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array{from: string, branch: string, to: ?string, index: int, count: int}>
     */
    public function edges(array $nodes): array
    {
        $byUid = [];

        foreach ($nodes as $node) {
            $byUid[(string) ($node['uid'] ?? '')] = true;
        }

        $edges = [];

        foreach ($nodes as $node) {
            $type = AutomationNodeType::tryFrom((string) ($node['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            $branches = AutomationNode::branchKeysFor($type, (array) ($node['config'] ?? []));
            $count = count($branches);

            foreach (array_values($branches) as $index => $branch) {
                $target = (string) (($node['edges'][$branch] ?? '') ?? '');

                $edges[] = [
                    'from' => (string) $node['uid'],
                    'branch' => $branch,
                    'to' => $target !== '' && isset($byUid[$target]) ? $target : null,
                    'index' => $index,
                    'count' => $count,
                ];
            }
        }

        return $edges;
    }

    /** @param array<string, mixed> $node */
    private function hasPosition(array $node): bool
    {
        return isset($node['x'], $node['y'])
            && is_numeric($node['x'])
            && is_numeric($node['y']);
    }

    /** @param array<string, array<string, mixed>> $byUid */
    private function rootUid(array $byUid): string
    {
        foreach ($byUid as $uid => $node) {
            $type = AutomationNodeType::tryFrom((string) ($node['type'] ?? ''));

            if ($type !== null && $type->isTrigger()) {
                return (string) $uid;
            }
        }

        return (string) array_key_first($byUid);
    }
}
