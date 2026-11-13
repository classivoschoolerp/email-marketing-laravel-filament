<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\EmailList;
use League\Csv\Info;
use League\Csv\Reader;

/**
 * Pre-import CSV analysis (spec F-2.2.7-001): delimiter/enclosure
 * auto-detection, header-row heuristic, sample rows for the mapping UI, and
 * the header → field mapping suggestion (tag-name heuristic with manual
 * override in the UI).
 */
class CsvAnalyzer
{
    /** Mapping target: the subscriber email column. */
    public const TARGET_EMAIL = 'EMAIL';

    /** Mapping target: ignore this column. */
    public const TARGET_SKIP = '_skip';

    /** Mapping target prefix: existing custom field by tag. */
    public const TARGET_FIELD_PREFIX = 'field:';

    /** Mapping target prefix: auto-create a Text field from this header. */
    public const TARGET_NEW_PREFIX = 'new:';

    private const CANDIDATE_DELIMITERS = [',', ';', "\t", '|'];

    private const EMAIL_HEADER_ALIASES = ['EMAIL', 'E_MAIL', 'EMAIL_ADDRESS', 'MAIL', 'EMAILADDRESS'];

    /**
     * Detect the delimiter with the highest cell yield over the first rows.
     */
    public function detectDelimiter(string $csvContent): string
    {
        $reader = Reader::createFromString($csvContent);
        $stats = Info::getDelimiterStats($reader, self::CANDIDATE_DELIMITERS, 25);

        arsort($stats);
        $best = array_key_first($stats);

        return ($best === null || $stats[$best] === 0) ? ',' : (string) $best;
    }

    /**
     * Detect the enclosure character: prefer the one actually wrapping cells
     * in the sample; defaults to a double quote.
     */
    public function detectEnclosure(string $csvContent, string $delimiter): string
    {
        $lines = array_slice(preg_split('/\r\n|\r|\n/', $csvContent) ?: [], 0, 25);

        foreach (['"', "'"] as $candidate) {
            foreach ($lines as $line) {
                foreach (explode($delimiter, $line) as $cell) {
                    $cell = trim($cell);

                    if (strlen($cell) >= 2 && str_starts_with($cell, $candidate) && str_ends_with($cell, $candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return '"';
    }

    /**
     * Heuristic: the first row is a header when none of its cells looks like
     * an email address.
     *
     * @param  list<string|null>  $firstRow
     */
    public function looksLikeHeader(array $firstRow): bool
    {
        foreach ($firstRow as $cell) {
            if ($cell !== null && filter_var(trim($cell), FILTER_VALIDATE_EMAIL) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * First rows of the CSV for the mapping preview.
     *
     * @return list<list<string|null>>
     */
    public function sampleRows(string $csvContent, string $delimiter, string $enclosure, int $limit = 5): array
    {
        $reader = Reader::createFromString($csvContent);
        $reader->setDelimiter($delimiter);
        $reader->setEnclosure($enclosure);

        $rows = [];

        foreach ($reader->getRecords() as $record) {
            $rows[] = array_values($record);

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Suggest a column → target mapping from headers using the tag-name
     * heuristic (F-2.2.7-001): normalized header matched against the list's
     * field tags and labels; unmatched columns become "new:" (auto-create,
     * F-2.2.7-007) so the UI can offer create-or-skip.
     *
     * @param  list<string|null>  $headers
     * @return array<int, string> column index => mapping target
     */
    public function suggestMapping(array $headers, EmailList $list): array
    {
        $fields = $list->fields()->get();

        $byTag = [];
        $byLabel = [];

        foreach ($fields as $field) {
            $byTag[$this->normalize((string) $field->tag)] = (string) $field->tag;
            $byLabel[$this->normalize((string) $field->label)] = (string) $field->tag;
        }

        $mapping = [];
        $emailMapped = false;

        foreach ($headers as $index => $header) {
            $normalized = $this->normalize((string) $header);

            if ($normalized === '') {
                $mapping[$index] = self::TARGET_SKIP;

                continue;
            }

            if (! $emailMapped && in_array($normalized, self::EMAIL_HEADER_ALIASES, true)) {
                $mapping[$index] = self::TARGET_EMAIL;
                $emailMapped = true;

                continue;
            }

            $tag = $byTag[$normalized] ?? $byLabel[$normalized] ?? null;

            $mapping[$index] = $tag !== null
                ? self::TARGET_FIELD_PREFIX.$tag
                : self::TARGET_NEW_PREFIX.trim((string) $header);
        }

        return $mapping;
    }

    /** Uppercase, non-alphanumerics collapsed to underscores ("First Name" → FIRST_NAME). */
    public function normalize(string $value): string
    {
        $value = strtoupper(trim($value));

        return trim((string) preg_replace('/[^A-Z0-9]+/', '_', $value), '_');
    }

    /** Derive a valid, unique merge tag from a CSV header (F-2.2.7-007). */
    public function tagFromHeader(string $header, EmailList $list): string
    {
        $base = $this->normalize($header);

        if ($base === '' || ! preg_match('/^[A-Z]/', $base)) {
            $base = 'FIELD'.($base === '' ? '' : '_'.$base);
        }

        $base = substr($base, 0, 60);
        $tag = $base;
        $suffix = 2;

        while (\App\Models\ListField::isReservedTag($tag)
            || $list->fields()->where('tag', $tag)->exists()) {
            $tag = $base.'_'.$suffix;
            $suffix++;
        }

        return $tag;
    }
}
