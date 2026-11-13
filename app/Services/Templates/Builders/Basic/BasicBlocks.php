<?php

declare(strict_types=1);

namespace App\Services\Templates\Builders\Basic;

use Illuminate\Support\Str;

/**
 * The block model behind the basic/simple built-in builder (spec §2.5.3
 * F-2.5.3-001).
 *
 * A document is an ordered list of blocks; each block is a flat associative
 * array with a `type` and that type's own fields. This class is the schema:
 * it defines the types, their defaults, and the normalisation every write
 * passes through — so a block list coming from the panel, an import or an
 * API call is always the same shape by the time the renderer sees it.
 *
 * Deliberately NOT GrapesJS (D-11 keeps GrapesJS as the P1 builder): there
 * is no canvas, no component tree and no client-side state. Blocks live on
 * the server, the UI edits them over Livewire, and the HTML is regenerated
 * server-side on every change.
 */
final class BasicBlocks
{
    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_BUTTON = 'button';

    public const TYPE_DIVIDER = 'divider';

    /**
     * WP-35 §2.3.3: the RSS-to-email block (F-2.3.3-011 / F-2.3.3-024) and the
     * countdown timer (F-2.3.3-012). Both are DATA blocks — they carry a
     * source, not markup — and the renderer resolves them at build time.
     */
    public const TYPE_RSS = 'rss';

    public const TYPE_COUNTDOWN = 'countdown';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_IMAGE,
        self::TYPE_BUTTON,
        self::TYPE_DIVIDER,
        self::TYPE_RSS,
        self::TYPE_COUNTDOWN,
    ];

    public const MAX_BLOCKS = 100;

    /** Schema marker stored alongside the blocks in `content_json`. */
    public const FORMAT = 'basic-builder/1';

    /* ================================================================ *
     *  Defaults
     * ================================================================ */

    /** @return array<string, mixed> */
    public static function make(string $type): array
    {
        $block = match ($type) {
            self::TYPE_IMAGE => [
                'type' => self::TYPE_IMAGE,
                'src' => '',
                'alt' => '',
                'href' => '',
                'width' => 600,
                'align' => 'center',
            ],
            self::TYPE_BUTTON => [
                'type' => self::TYPE_BUTTON,
                'label' => __('templates.basic_builder.defaults.button_label'),
                'href' => '',
                'background' => '#2563eb',
                'color' => '#ffffff',
                'align' => 'center',
            ],
            self::TYPE_DIVIDER => [
                'type' => self::TYPE_DIVIDER,
                'color' => '#e5e7eb',
                'thickness' => 1,
                'padding' => 16,
            ],
            self::TYPE_RSS => [
                'type' => self::TYPE_RSS,
                'url' => '',
                'items' => 5,
                'show_date' => true,
                'show_excerpt' => true,
                'color' => '#111827',
                'padding' => 16,
            ],
            self::TYPE_COUNTDOWN => [
                'type' => self::TYPE_COUNTDOWN,
                'until' => '',
                'label' => '',
                'expired_label' => '',
                'color' => '#111827',
                'background' => '#f3f4f6',
                'align' => 'center',
                'padding' => 16,
            ],
            default => [
                'type' => self::TYPE_TEXT,
                'content' => __('templates.basic_builder.defaults.text_content'),
                'align' => 'left',
                'color' => '#111827',
                'font_size' => 16,
                'padding' => 16,
            ],
        };

        $block['id'] = (string) Str::uuid();

        return $block;
    }

    /**
     * The starter document for a new basic template/campaign: a greeting and
     * the compliance footer, so the unsubscribe check (F-2.5.5-006) passes
     * out of the box exactly like EmailTemplateResource::starterHtml().
     *
     * @return list<array<string, mixed>>
     */
    public static function starter(): array
    {
        $intro = self::make(self::TYPE_TEXT);
        $intro['content'] = __('templates.starter.greeting');

        $divider = self::make(self::TYPE_DIVIDER);

        $footer = self::make(self::TYPE_TEXT);
        $footer['content'] = '{COMPANY_NAME} · {COMPANY_FULL_ADDRESS} · ['
            .__('templates.starter.unsubscribe').']({UNSUBSCRIBE_URL})';
        $footer['font_size'] = 12;
        $footer['color'] = '#6b7280';
        $footer['align'] = 'center';

        return [$intro, $divider, $footer];
    }

    /* ================================================================ *
     *  Normalisation
     * ================================================================ */

    /**
     * Coerce arbitrary input into a valid block list: unknown types dropped,
     * every field clamped to its domain, ids backfilled, count capped.
     *
     * @return list<array<string, mixed>>
     */
    public static function normalize(mixed $blocks): array
    {
        if (! is_iterable($blocks)) {
            return [];
        }

        $out = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = is_string($block['type'] ?? null) ? $block['type'] : '';

            if (! in_array($type, self::TYPES, true)) {
                continue;
            }

            $out[] = self::normalizeBlock($type, $block);

            if (count($out) >= self::MAX_BLOCKS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function normalizeBlock(string $type, array $block): array
    {
        $clean = self::make($type);

        $clean['id'] = is_string($block['id'] ?? null) && $block['id'] !== ''
            ? mb_substr($block['id'], 0, 64)
            : $clean['id'];

        return match ($type) {
            self::TYPE_IMAGE => array_merge($clean, [
                'src' => self::url($block['src'] ?? null),
                'alt' => self::text($block['alt'] ?? null, 200),
                'href' => self::url($block['href'] ?? null),
                'width' => self::intBetween($block['width'] ?? null, 40, 600, 600),
                'align' => self::align($block['align'] ?? null),
            ]),
            self::TYPE_BUTTON => array_merge($clean, [
                'label' => self::text($block['label'] ?? null, 120) ?: $clean['label'],
                'href' => self::url($block['href'] ?? null),
                'background' => self::color($block['background'] ?? null, $clean['background']),
                'color' => self::color($block['color'] ?? null, $clean['color']),
                'align' => self::align($block['align'] ?? null),
            ]),
            self::TYPE_DIVIDER => array_merge($clean, [
                'color' => self::color($block['color'] ?? null, $clean['color']),
                'thickness' => self::intBetween($block['thickness'] ?? null, 1, 20, 1),
                'padding' => self::intBetween($block['padding'] ?? null, 0, 80, 16),
            ]),
            self::TYPE_RSS => array_merge($clean, [
                'url' => self::url($block['url'] ?? null),
                'items' => self::intBetween($block['items'] ?? null, 1, 20, 5),
                'show_date' => (bool) ($block['show_date'] ?? true),
                'show_excerpt' => (bool) ($block['show_excerpt'] ?? true),
                'color' => self::color($block['color'] ?? null, $clean['color']),
                'padding' => self::intBetween($block['padding'] ?? null, 0, 80, 16),
            ]),
            self::TYPE_COUNTDOWN => array_merge($clean, [
                'until' => self::datetime($block['until'] ?? null),
                'label' => self::text($block['label'] ?? null, 120),
                'expired_label' => self::text($block['expired_label'] ?? null, 120),
                'color' => self::color($block['color'] ?? null, $clean['color']),
                'background' => self::color($block['background'] ?? null, $clean['background']),
                'align' => self::align($block['align'] ?? null),
                'padding' => self::intBetween($block['padding'] ?? null, 0, 80, 16),
            ]),
            default => array_merge($clean, [
                'content' => self::text($block['content'] ?? null, 5000),
                'align' => self::align($block['align'] ?? null),
                'color' => self::color($block['color'] ?? null, $clean['color']),
                'font_size' => self::intBetween($block['font_size'] ?? null, 9, 48, 16),
                'padding' => self::intBetween($block['padding'] ?? null, 0, 80, 16),
            ]),
        };
    }

    private static function text(mixed $value, int $max): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        // Newlines are meaningful (they become <br>); other control chars are not.
        $value = (string) preg_replace('/[^\P{C}\n]/u', '', (string) $value);

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * Only http(s)/mailto/tel targets and merge-tag URLs survive — this is
     * the XSS fence for every href/src the builder emits (javascript:,
     * data:, vbscript: and friends are dropped, never "sanitised").
     */
    private static function url(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return '';
        }

        // A tag-driven URL ({UNSUBSCRIBE_URL}, {WEB_VERSION_URL}, …) is resolved
        // at send time and is always safe.
        if (preg_match('/^\{[A-Z][A-Z0-9_]*\}$/u', $value) === 1) {
            return $value;
        }

        if (preg_match('#^(https?://|mailto:|tel:)#i', $value) !== 1) {
            return '';
        }

        return mb_substr($value, 0, 2048);
    }

    private static function color(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) === 1
            ? mb_strtolower($value)
            : $fallback;
    }

    /** ISO-8601 "Y-m-d H:i" (UTC) or '' — the countdown target instant. */
    private static function datetime(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return '';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->utc()->format('Y-m-d H:i');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function align(mixed $value): string
    {
        return in_array($value, ['left', 'center', 'right'], true) ? $value : 'left';
    }

    private static function intBetween(mixed $value, int $min, int $max, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (int) $value));
    }

    /* ================================================================ *
     *  content_json envelope
     * ================================================================ */

    /** @param list<array<string, mixed>> $blocks */
    public static function encode(array $blocks): string
    {
        return (string) json_encode([
            'format' => self::FORMAT,
            'blocks' => array_values($blocks),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Read a stored document back. Anything that is not a basic-builder
     * payload (a GrapesJS project, null, junk) yields an empty list — the
     * page then offers the starter document instead of crashing.
     *
     * @return list<array<string, mixed>>
     */
    public static function decode(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ($decoded['format'] ?? null) !== self::FORMAT) {
            return [];
        }

        return self::normalize($decoded['blocks'] ?? []);
    }

    /** @return array<string, string> value => label, for the "add block" menu */
    public static function typeOptions(): array
    {
        $options = [];

        foreach (self::TYPES as $type) {
            $options[$type] = __('templates.basic_builder.types.'.$type);
        }

        return $options;
    }
}
