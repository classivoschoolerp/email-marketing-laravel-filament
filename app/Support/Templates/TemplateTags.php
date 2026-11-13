<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Builder;

/**
 * Template tag helpers (spec §2.5.1 F-2.5.1-002, D-34 resolved in WP-27B).
 *
 * Tags are free-text labels stored on `email_templates.tags` (JSON list).
 * They are normalised on write so that:
 *   • "Newsletter", " newsletter " and "NEWSLETTER" are one tag;
 *   • whereJsonContains() — the portable filter that works on both SQLite and
 *     MySQL 8 (D-03) — is an exact match against a canonical value.
 */
final class TemplateTags
{
    /** Max tags per template; keeps the JSON column and the filter sane. */
    public const MAX_PER_TEMPLATE = 25;

    /** Max characters per tag. */
    public const MAX_LENGTH = 50;

    /**
     * Canonicalise a raw tag list: trim, lower-case, drop blanks, de-duplicate,
     * cap the length and the count. Order of first appearance is preserved.
     *
     * @param  iterable<mixed>|null  $tags
     * @return list<string>
     */
    public static function normalize(mixed $tags): array
    {
        if (! is_iterable($tags)) {
            return [];
        }

        $out = [];

        foreach ($tags as $tag) {
            if (! is_string($tag) && ! is_numeric($tag)) {
                continue;
            }

            $clean = mb_strtolower(trim((string) $tag));
            // Collapse internal whitespace so "black friday" == "black  friday".
            $clean = (string) preg_replace('/\s+/u', ' ', $clean);

            if ($clean === '') {
                continue;
            }

            $clean = mb_substr($clean, 0, self::MAX_LENGTH);

            if (! in_array($clean, $out, true)) {
                $out[] = $clean;
            }

            if (count($out) >= self::MAX_PER_TEMPLATE) {
                break;
            }
        }

        return $out;
    }

    /**
     * Distinct tags across everything the current tenant may browse (own rows
     * plus visible gallery rows) — the option source for the index filter.
     * Tenancy is the browsable() scope's, never a raw table scan, so one
     * customer can never see another's tag vocabulary.
     *
     * @return array<string, string> value => label (identical; tags are canonical)
     */
    public static function options(): array
    {
        $tags = [];

        EmailTemplate::query()
            ->browsable()
            ->whereNotNull('tags')
            ->select(['id', 'tags'])
            ->chunkById(500, function ($rows) use (&$tags): void {
                foreach ($rows as $row) {
                    foreach (self::normalize($row->tags) as $tag) {
                        $tags[$tag] = $tag;
                    }
                }
            });

        ksort($tags);

        return $tags;
    }

    /** Filter a template query down to rows carrying the given tag. */
    public static function scopeTagged(Builder $query, string $tag): Builder
    {
        $normalized = self::normalize([$tag]);

        if ($normalized === []) {
            return $query;
        }

        return $query->whereJsonContains('tags', $normalized[0]);
    }
}
