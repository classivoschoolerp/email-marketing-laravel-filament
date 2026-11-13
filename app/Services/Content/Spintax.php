<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * Spintax engine (spec §2.3.3 F-2.3.3-021/022 — P1, critical for cold
 * outreach). Syntax: `{Hi|Hello|Hey}` with arbitrary nesting:
 * `{Hi {there|friend}|Hello}`.
 *
 * Merge tags share the brace syntax (`{SUBSCRIBER_NAME}`, D-22) — a braced
 * group is only treated as spintax when it contains at least one TOP-LEVEL
 * pipe. `{UNSUBSCRIBE_URL}` therefore passes through untouched, as does any
 * unbalanced brace.
 *
 * Rendering is deterministic per (subscriber uid + campaign uid) seed: the
 * same recipient always gets the same permutation, across workers and
 * retries. Group choices hash the seed with the group's document-order
 * index so each group varies independently.
 */
class Spintax
{
    private const NODE_TEXT = 'text';

    private const NODE_GROUP = 'group';

    /**
     * Render one deterministic permutation for the given seed.
     * Campaign send path uses seed = subscriber uid + ':' + campaign uid.
     */
    public function render(string $text, string $seed): string
    {
        $groupIndex = 0;

        return $this->renderNodes($this->parse($text), $seed, $groupIndex);
    }

    /**
     * Total number of distinct permutations. Nested groups multiply within
     * their alternative only. Returns PHP_INT_MAX on overflow.
     */
    public function permutationCount(string $text): int
    {
        return $this->countNodes($this->parse($text));
    }

    /**
     * Sample permutations for the preview/expander (F-2.3.3-022). Attempts
     * to return $count distinct permutations (bounded by permutationCount).
     *
     * @return list<string>
     */
    public function samples(string $text, int $count = 5, string $seedPrefix = 'preview'): array
    {
        $count = max(1, $count);
        $unique = [];
        $attempts = 0;
        $max = $count * 20;

        while (count($unique) < $count && $attempts < $max) {
            $sample = $this->render($text, $seedPrefix.'-'.$attempts);
            $unique[$sample] = true;
            $attempts++;
        }

        return array_keys($unique);
    }

    /** Does the text contain at least one spintax group? */
    public function hasSpintax(string $text): bool
    {
        foreach ($this->parse($text) as $node) {
            if ($node['type'] === self::NODE_GROUP) {
                return true;
            }

            // Text nodes are flat, no need to recurse — parse() only nests
            // inside groups.
        }

        return $this->permutationCount($text) > 1;
    }

    /* ---------------------------------------------------------------- *
     *  Parser — recursive descent over `{ a | b }` groups.
     * ---------------------------------------------------------------- */

    /**
     * @return list<array{type: string, value?: string, options?: list<list<array<string, mixed>>>}>
     */
    private function parse(string $text): array
    {
        $pos = 0;

        return $this->parseSequence($text, $pos, false);
    }

    /**
     * Parse until end of string, or (inside a group) until an unconsumed
     * top-level '|' or '}' — the caller handles those.
     *
     * @return list<array<string, mixed>>
     */
    private function parseSequence(string $text, int &$pos, bool $inGroup): array
    {
        $nodes = [];
        $literal = '';
        $length = strlen($text);

        while ($pos < $length) {
            $char = $text[$pos];

            if ($inGroup && ($char === '|' || $char === '}')) {
                break; // group delimiter — caller consumes it
            }

            if ($char === '{') {
                $parsed = $this->tryParseGroup($text, $pos);

                if ($parsed !== null) {
                    if ($literal !== '') {
                        $nodes[] = ['type' => self::NODE_TEXT, 'value' => $literal];
                        $literal = '';
                    }

                    // Either [group] or a literal-brace sequence (merge tag)
                    // whose inner nodes may still contain nested groups.
                    foreach ($parsed as $node) {
                        $nodes[] = $node;
                    }

                    continue;
                }

                // Unbalanced '{' — plain literal character.
            }

            $literal .= $char;
            $pos++;
        }

        if ($literal !== '') {
            $nodes[] = ['type' => self::NODE_TEXT, 'value' => $literal];
        }

        return $nodes;
    }

    /**
     * Attempt to parse a braced run at '{'.
     *
     * Returns:
     *  - [groupNode] when the run has at least one top-level pipe (spintax);
     *  - a literal sequence "{" + inner nodes + "}" when it closes without a
     *    pipe (merge tag — inner nested spintax groups still render);
     *  - null (with $pos restored) when the brace never closes.
     *
     * @return list<array<string, mixed>>|null
     */
    private function tryParseGroup(string $text, int &$pos): ?array
    {
        $start = $pos;
        $pos++; // consume '{'

        $options = [];
        $sawPipe = false;
        $length = strlen($text);

        while (true) {
            $options[] = $this->parseSequence($text, $pos, true);

            if ($pos >= $length) {
                // Unbalanced '{' — not a group.
                $pos = $start;

                return null;
            }

            if ($text[$pos] === '|') {
                $sawPipe = true;
                $pos++; // consume '|', parse next alternative

                continue;
            }

            // '}' — run closed.
            $pos++;

            if (! $sawPipe) {
                // `{SINGLE}` = merge tag: literal braces around the inner
                // nodes (which may themselves contain spintax groups).
                return array_merge(
                    [['type' => self::NODE_TEXT, 'value' => '{']],
                    $options[0],
                    [['type' => self::NODE_TEXT, 'value' => '}']],
                );
            }

            return [['type' => self::NODE_GROUP, 'options' => $options]];
        }
    }

    /* ---------------------------------------------------------------- *
     *  Rendering + counting.
     * ---------------------------------------------------------------- */

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function renderNodes(array $nodes, string $seed, int &$groupIndex): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if ($node['type'] === self::NODE_TEXT) {
                $out .= $node['value'];

                continue;
            }

            /** @var list<list<array<string, mixed>>> $options */
            $options = $node['options'];
            $choice = $this->pick($seed, $groupIndex, count($options));
            $groupIndex++;

            // Skipped alternatives still advance the group counter so every
            // group keeps a stable index regardless of sibling choices.
            foreach ($options as $i => $option) {
                if ($i === $choice) {
                    $out .= $this->renderNodes($option, $seed, $groupIndex);
                } else {
                    $this->skipCount($option, $groupIndex);
                }
            }
        }

        return $out;
    }

    /**
     * Advance the group counter over an unrendered subtree.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private function skipCount(array $nodes, int &$groupIndex): void
    {
        foreach ($nodes as $node) {
            if ($node['type'] === self::NODE_GROUP) {
                $groupIndex++;

                foreach ($node['options'] as $option) {
                    $this->skipCount($option, $groupIndex);
                }
            }
        }
    }

    /**
     * Deterministic option pick: hash of seed + group position.
     */
    private function pick(string $seed, int $groupIndex, int $optionCount): int
    {
        if ($optionCount <= 1) {
            return 0;
        }

        $hash = hash('xxh128', $seed.'#'.$groupIndex);

        // 13 hex chars = 52 bits — safely inside PHP's integer range.
        return (int) (hexdec(substr($hash, 0, 13)) % $optionCount);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function countNodes(array $nodes): int
    {
        $total = 1;

        foreach ($nodes as $node) {
            if ($node['type'] === self::NODE_TEXT) {
                continue;
            }

            $groupTotal = 0;

            foreach ($node['options'] as $option) {
                $groupTotal = $this->addCapped($groupTotal, $this->countNodes($option));
            }

            $total = $this->multiplyCapped($total, max(1, $groupTotal));
        }

        return $total;
    }

    private function addCapped(int $a, int $b): int
    {
        return ($a > PHP_INT_MAX - $b) ? PHP_INT_MAX : $a + $b;
    }

    private function multiplyCapped(int $a, int $b): int
    {
        if ($a !== 0 && $b > intdiv(PHP_INT_MAX, $a)) {
            return PHP_INT_MAX;
        }

        return $a * $b;
    }
}
