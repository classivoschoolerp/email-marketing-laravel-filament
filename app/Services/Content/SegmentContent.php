<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Segments\SegmentQueryBuilder;
use Throwable;

/**
 * Dynamic content by segment — "smart content" (spec §2.3.3 F-2.3.3-009).
 *
 * THE SYNTAX is the existing F-2.3.3-008 conditional vocabulary with one new
 * predicate, so a customer who already knows `[IF FNAME = 'Ana']` needs
 * nothing new:
 *
 *     [IF IN SEGMENT 'VIPs']…[ELSE]…[ENDIF]
 *     [IF NOT IN SEGMENT 'Lapsed']…[ENDIF]
 *
 * The reference is a segment NAME or UID, quoted or bare. It is resolved
 * inside {@see ConditionalContent::evaluate()}, which runs before merge-tag
 * substitution, so the untaken branch never reaches the tag pass and every
 * surface (send path, web view, test send, preview) inherits it at once —
 * exactly the D-62 order F-2.3.3-008 already relies on.
 *
 * TENANCY (D-90, applied verbatim). A segment is resolved ONLY inside the
 * sending tenant. An unknown name, a deleted segment or one belonging to
 * another customer matches NOTHING IN BOTH DIRECTIONS: `[IF IN SEGMENT 'x']`
 * is false AND `[IF NOT IN SEGMENT 'x']` is false, so both render their ELSE
 * branch (empty when there is none). Widening on a broken reference would mail
 * personalised content to people the customer never targeted; narrowing to
 * "everyone is a non-member" would be an equally invented claim.
 *
 * COST. Membership is one `EXISTS`-shaped query per (segment, recipient):
 * SegmentQueryBuilder builds the segment's own subscriber query and the
 * recipient's primary key is pushed into it, so the database answers with an
 * index lookup and never materialises the member set. Within one render the
 * answer is memoised, so a body repeating `[IF IN SEGMENT 'VIPs']` five times
 * costs one query, not five; segment RESOLUTION (name → row) is memoised for
 * the lifetime of the process, so a batch worker resolves each name once for
 * the whole campaign. Nothing is cached across processes on purpose —
 * a dynamic segment is defined as evaluated at read time (F-2.2.10-012), and
 * a stale cache would send the wrong branch.
 *
 * PREVIEWS. A preview has no recipient. Rather than render nothing (which
 * looks like a bug) or invent a membership, previews evaluate segment
 * predicates as MATCHED — the personalised branch is what the customer is
 * trying to look at — and the preview UI says so in as many words.
 *
 * This class never edits App\Services\Segments\**; it only calls it.
 */
final class SegmentContent
{
    /** `[IF (NOT )IN SEGMENT '<name or uid>']` */
    public const PREDICATE = '/^(?<not>not\s+)?in\s+segment\b\s*(?<ref>.*)$/iu';

    /**
     * Process-lifetime memo of name/uid → segment id, per tenant.
     * Value NULL means "resolved and does not exist for this tenant".
     *
     * @var array<string, int|null>
     */
    private static array $resolved = [];

    /** @var array<string, bool> per-instance (= per render) membership memo */
    private array $membership = [];

    /** @var array<int, ListSegment|null> per-instance loaded-segment memo */
    private array $loaded = [];

    public function __construct(private readonly SegmentQueryBuilder $segments) {}

    /** True when `$condition` is a segment predicate rather than a tag test. */
    public function handles(string $condition): bool
    {
        return preg_match(self::PREDICATE, trim($condition)) === 1;
    }

    /**
     * Evaluate one `[IF (NOT )IN SEGMENT '…']` condition.
     *
     * @param  string  $condition  the raw text between `[IF` and `]`
     */
    public function evaluate(string $condition, ?int $customerId, ?int $subscriberId, bool $preview = false): bool
    {
        if (preg_match(self::PREDICATE, trim($condition), $match) !== 1) {
            return false;
        }

        $reference = $this->unquote(trim($match['ref']));
        $negated = trim($match['not']) !== '';

        if ($reference === '' || $customerId === null) {
            return false; // D-90: nothing to resolve ⇒ nothing matches.
        }

        $segmentId = $this->resolve($customerId, $reference);

        if ($segmentId === null) {
            // Unknown or cross-tenant: false in BOTH directions (D-90).
            return false;
        }

        if ($preview) {
            // No recipient exists; show the personalised branch (see docblock).
            return ! $negated;
        }

        if ($subscriberId === null) {
            return false;
        }

        $member = $this->isMember($segmentId, $customerId, $subscriberId);

        return $negated ? ! $member : $member;
    }

    /**
     * Segment id for a name or uid inside ONE tenant, or null.
     *
     * Tenancy is dropped and re-applied explicitly because rendering happens
     * in queued/console contexts where no current customer is bound — the
     * `customer_id` predicate here IS the fence.
     */
    public function resolve(int $customerId, string $reference): ?int
    {
        $key = $customerId.'|'.mb_strtolower($reference);

        if (array_key_exists($key, self::$resolved)) {
            return self::$resolved[$key];
        }

        $segment = ListSegment::withoutTenancy()
            ->where('customer_id', $customerId)
            ->where(function ($query) use ($reference): void {
                $query->where('uid', $reference)->orWhere('name', $reference);
            })
            // A uid match is exact and unambiguous; prefer it over a name.
            ->orderByRaw('case when uid = ? then 0 else 1 end', [$reference])
            ->orderBy('id')
            ->first(['id']);

        return self::$resolved[$key] = $segment?->id === null ? null : (int) $segment->id;
    }

    /** Segment references used by a body, in order of first appearance. */
    /** @return list<string> */
    public function referencesIn(string $content): array
    {
        if ($content === '' || stripos($content, '[IF') === false) {
            return [];
        }

        preg_match_all('/\[IF\s+(?:not\s+)?in\s+segment\b\s*(?<ref>[^\]]*)\]/iu', $content, $matches);

        $references = [];

        foreach ($matches['ref'] ?? [] as $raw) {
            $reference = $this->unquote(trim($raw));

            if ($reference !== '' && ! in_array($reference, $references, true)) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /** Forget the process-lifetime resolution memo (tests, long-lived workers). */
    public static function flushResolved(): void
    {
        self::$resolved = [];
    }

    /* ---------------------------------------------------------------- */

    private function isMember(int $segmentId, int $customerId, int $subscriberId): bool
    {
        $key = $segmentId.':'.$subscriberId;

        if (array_key_exists($key, $this->membership)) {
            return $this->membership[$key];
        }

        try {
            $segment = $this->loaded[$segmentId] ??= ListSegment::withoutTenancy()
                ->where('customer_id', $customerId)
                ->whereKey($segmentId)
                ->first();

            $member = $segment !== null
                && $this->segments->subscribers($segment)
                    ->withoutGlobalScopes()
                    ->whereKey($subscriberId)
                    // Belt and braces: the recipient must be this tenant's too.
                    ->where(Subscriber::query()->getModel()->getTable().'.customer_id', $customerId)
                    ->exists();
        } catch (Throwable) {
            // A malformed stored condition must not break a send: it renders
            // the ELSE branch, exactly like an unknown segment (D-90).
            $member = false;
        }

        return $this->membership[$key] = $member;
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
