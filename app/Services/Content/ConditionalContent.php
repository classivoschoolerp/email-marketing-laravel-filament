<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * Conditional content blocks (spec §2.3.3 F-2.3.3-008), the syntax the spec
 * names verbatim:
 *
 *     [IF FNAME = 'Ana']Hi Ana![ELSE]Hello there![ENDIF]
 *
 * Grammar
 *   [IF <TAG> <operator> '<value>'] … [ELSE] … [ENDIF]
 *   [IF <TAG> is empty] … [ENDIF]        (and `is not empty` — no value)
 *
 *   <TAG>       an uppercase merge-tag name, with or without braces.
 *   <operator>  =  !=  <>  >  <  >=  <=  contains  not contains
 *               starts with  ends with  is empty  is not empty
 *   <value>     single- or double-quoted, or a bare token without spaces.
 *
 * Evaluation runs INSIDE MergeTags::render(), before tag substitution, so
 * every surface gets it at once: the send path, the web view, test sends and
 * previews. It resolves the innermost block first and loops, which supports
 * nesting to any depth without a recursive-descent parser.
 *
 * An unknown tag compares as an EMPTY value (so `[IF NOPE = 'x']` is false and
 * `[IF NOPE is empty]` is true) — the same convention the merge-tag scanner
 * uses, and the only one that cannot show a half-rendered condition to a
 * recipient. Numeric comparisons happen when BOTH sides look numeric,
 * otherwise the comparison is a case-insensitive string comparison.
 *
 * WP-46 (§2.3.3 F-2.3.3-009) adds ONE more condition form to the same
 * grammar — smart content by segment:
 *
 *     [IF IN SEGMENT 'VIPs'] … [ELSE] … [ENDIF]
 *     [IF NOT IN SEGMENT 'Lapsed'] … [ENDIF]
 *
 * It is resolved by {@see SegmentContent}, which owns the tenancy and cost
 * rules. Nothing else about this class changed: the same innermost-first loop,
 * the same [ELSE] split, the same nesting, so a segment block may contain a
 * tag block and vice versa.
 *
 * The render CONTEXT (which tenant, which recipient, is this a preview) rides
 * in the same `$values` map under the three reserved keys below. They can
 * never be mistaken for merge tags: MergeTags only substitutes braced runs
 * matching `[A-Z][A-Z0-9_]*`, which a leading underscore cannot satisfy.
 */
class ConditionalContent
{
    /** Sending tenant id — set by MergeTags::values()/sampleValues(). */
    public const CONTEXT_CUSTOMER = '__CUSTOMER_ID';

    /** Recipient subscriber id; absent for previews and test renders. */
    public const CONTEXT_SUBSCRIBER = '__SUBSCRIBER_ID';

    /** '1' when this render is a preview/sample rather than a real send. */
    public const CONTEXT_PREVIEW = '__PREVIEW';

    /** Safety valve for pathological input; each pass resolves one nesting level. */
    private const MAX_PASSES = 25;

    /**
     * Resolved once per instance — and MergeTags resolves one instance per
     * render — so SegmentContent's membership memo covers the whole document.
     * A body repeating the same segment block ten times costs one lookup.
     */
    private ?SegmentContent $segments = null;

    /**
     * @param  array<string, string>  $values
     */
    public function render(string $content, array $values): string
    {
        if ($content === '' || stripos($content, '[IF') === false) {
            return $content;
        }

        // Innermost block: an [IF …] … [ENDIF] whose body contains no [IF.
        $pattern = '/\[IF\s+(?<condition>(?:(?!\[IF\b).)*?)\]'
            .'(?<body>(?:(?!\[IF\b|\[ENDIF\]).)*?)'
            .'\[ENDIF\]/isu';

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $replaced = preg_replace_callback(
                $pattern,
                function (array $match) use ($values): string {
                    [$then, $else] = $this->branches($match['body']);

                    return $this->evaluate($match['condition'], $values) ? $then : $else;
                },
                $content,
                -1,
                $count,
            );

            if ($replaced === null || $count === 0) {
                break;
            }

            $content = $replaced;
        }

        return $content;
    }

    /** True when the content uses the syntax at all (for the preflight hint). */
    public function has(string $content): bool
    {
        return preg_match('/\[IF\s+[^\]]+\]/iu', $content) === 1;
    }

    /**
     * Unbalanced markers, for CampaignPreflight — an [IF] with no [ENDIF]
     * would silently swallow the rest of the email.
     */
    public function isBalanced(string $content): bool
    {
        return preg_match_all('/\[IF\s+[^\]]*\]/iu', $content)
            === preg_match_all('/\[ENDIF\]/iu', $content);
    }

    /**
     * Split a block body on its top-level [ELSE].
     *
     * @return array{0: string, 1: string}
     */
    private function branches(string $body): array
    {
        $parts = preg_split('/\[ELSE\]/iu', $body, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /** @param array<string, string> $values */
    private function evaluate(string $condition, array $values): bool
    {
        $condition = trim($condition);

        // WP-46 (F-2.3.3-009): the segment predicate is checked first so
        // `IN SEGMENT` can never be mis-read as a tag named IN.
        $segments = $this->segments ??= app(SegmentContent::class);

        if ($segments->handles($condition)) {
            return $segments->evaluate(
                $condition,
                $this->intContext($values, self::CONTEXT_CUSTOMER),
                $this->intContext($values, self::CONTEXT_SUBSCRIBER),
                preview: (string) ($values[self::CONTEXT_PREVIEW] ?? '') === '1',
            );
        }

        if (! preg_match(
            '/^\{?(?<tag>[A-Z][A-Z0-9_]*)\}?\s*'
            .'(?<op>is\s+not\s+empty|is\s+empty|not\s+contains|contains|starts\s+with|ends\s+with|!=|<>|>=|<=|=|>|<)'
            .'\s*(?<value>.*)$/iu',
            $condition,
            $match,
        )) {
            return false;
        }

        $left = (string) ($values[mb_strtoupper($match['tag'])] ?? '');
        $operator = mb_strtolower((string) preg_replace('/\s+/', ' ', trim($match['op'])));
        $right = $this->unquote(trim($match['value']));

        return match ($operator) {
            'is empty' => trim($left) === '',
            'is not empty' => trim($left) !== '',
            'contains' => $right !== '' && mb_stripos($left, $right) !== false,
            'not contains' => $right === '' || mb_stripos($left, $right) === false,
            'starts with' => $right !== '' && mb_stripos($left, $right) === 0,
            'ends with' => $right !== '' && str_ends_with(mb_strtolower($left), mb_strtolower($right)),
            '=' => $this->compare($left, $right) === 0,
            '!=', '<>' => $this->compare($left, $right) !== 0,
            '>' => $this->compare($left, $right) > 0,
            '<' => $this->compare($left, $right) < 0,
            '>=' => $this->compare($left, $right) >= 0,
            '<=' => $this->compare($left, $right) <= 0,
            default => false,
        };
    }

    /** Numeric when both sides are numeric, case-insensitive strings otherwise. */
    private function compare(string $left, string $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left <=> (float) $right;
        }

        return strcmp(mb_strtolower(trim($left)), mb_strtolower(trim($right)));
    }

    /** @param array<string, string> $values */
    private function intContext(array $values, string $key): ?int
    {
        $raw = trim((string) ($values[$key] ?? ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }

    private function unquote(string $value): string
    {
        if (mb_strlen($value) >= 2) {
            $first = mb_substr($value, 0, 1);
            $last = mb_substr($value, -1);

            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
                return mb_substr($value, 1, -1);
            }
        }

        return $value;
    }
}
