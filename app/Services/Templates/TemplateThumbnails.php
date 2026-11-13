<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Models\EmailTemplate;
use DOMDocument;
use DOMElement;
use DOMText;
use Illuminate\Support\Str;

/**
 * Thumbnail resolution (spec §2.5.5 F-2.5.5-003 "auto-generate screenshot").
 *
 * HONEST SCOPE — READ THIS BEFORE CLAIMING THE ROW.
 * A screenshot means rasterising the template in a browser engine. That needs
 * headless Chrome (or an equivalent), which is NOT installed on this machine
 * and must not be installed; see docs/e2e-checklist.md. NOTHING here takes a
 * screenshot and nothing here should be described as one.
 *
 * What WP-27B added instead is a real improvement over "grey placeholder
 * rectangle": a deterministic, server-rendered SVG preview DERIVED from the
 * template's own markup — its dominant background/accent colours, its first
 * heading, whether it carries an image, and a bar per real text block sized
 * from that block's actual length. Same HTML in, byte-identical SVG out; no
 * browser, no network, no storage write.
 *
 * Resolution chain (first hit wins):
 *   1. an explicitly uploaded thumbnail (admin gallery) is left untouched
 *   2. the first real <img> in the content becomes the thumbnail
 *   3. content with text → the derived SVG preview (data: URI)
 *   4. genuinely empty content → the bundled placeholder SVG
 * Steps 1–2 write `thumbnail_path`; step 3 is computed on read by
 * {@see EmailTemplate::thumbnailUrl()} so nothing large lands in the column.
 */
class TemplateThumbnails
{
    /** Rendered thumbnail box (viewBox units). */
    private const WIDTH = 600;

    private const HEIGHT = 400;

    /**
     * Rendering defaults used only when the markup declares no colour of its
     * own. These are presentation fallbacks for the CARD, never claims about
     * the template.
     */
    private const DEFAULT_BACKGROUND = '#ffffff';

    private const DEFAULT_ACCENT = '#94a3b8';

    private const DEFAULT_TEXT = '#111827';

    /** Re-derive the thumbnail from content unless one was explicitly uploaded. */
    public function refreshFromContent(EmailTemplate $template): void
    {
        if ($template->thumbnail_uploaded) {
            return;
        }

        $extracted = $this->firstImageSource($template->content_html);

        if ($extracted !== $template->thumbnail_path) {
            $template->forceFill(['thumbnail_path' => $extracted])->saveQuietly();
        }
    }

    /** Record an explicitly uploaded thumbnail so content saves never overwrite it. */
    public function setUploaded(EmailTemplate $template, string $storagePath): void
    {
        $template->forceFill([
            'thumbnail_path' => $storagePath,
            'thumbnail_uploaded' => true,
        ])->saveQuietly();
    }

    public function firstImageSource(?string $html): ?string
    {
        $doc = $this->parse($html);

        if ($doc === null) {
            return null;
        }

        foreach ($doc->getElementsByTagName('img') as $img) {
            $src = trim($img->getAttribute('src'));

            // Skip tracking pixels / empty / merge-tag sources.
            if ($src === '' || str_contains($src, '{')) {
                continue;
            }

            return Str::limit($src, 2000, '');
        }

        return null;
    }

    /* ================================================================ *
     *  Derived preview (F-2.5.5-003, WP-27B) — NOT a screenshot
     * ================================================================ */

    /**
     * Data URI of the derived preview, or null when the template has no
     * content worth previewing (the caller then falls back to the bundled
     * placeholder).
     */
    public function previewDataUri(EmailTemplate $template): ?string
    {
        $svg = $this->previewSvg($template->content_html, (string) $template->name);

        return $svg === null ? null : 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Build the SVG. Deterministic: the same markup always produces the same
     * bytes, so the thumbnail is safely cacheable and diffable in tests.
     */
    public function previewSvg(?string $html, string $fallbackTitle = ''): ?string
    {
        $doc = $this->parse($html);

        if ($doc === null) {
            return null;
        }

        $blocks = $this->textBlocks($doc);
        $heading = $this->heading($doc) ?? ($blocks[0] ?? null) ?? trim($fallbackTitle);

        if ($heading === '' && $blocks === []) {
            return null; // markup with no readable content at all
        }

        $palette = $this->palette((string) $html);
        $hasImage = $this->firstImageSource($html) !== null
            || $doc->getElementsByTagName('img')->length > 0;

        return $this->render($palette, $heading, $blocks, $hasImage);
    }

    /**
     * @param  array{background: string, accent: string, text: string}  $palette
     * @param  list<string>  $blocks
     */
    private function render(array $palette, string $heading, array $blocks, bool $hasImage): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;

        $parts = [
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" width="'.$w.'" height="'.$h.'" role="img">',
            '<rect width="'.$w.'" height="'.$h.'" fill="'.$this->e($palette['background']).'"/>',
            // Accent band — the template's own dominant non-background colour.
            '<rect x="0" y="0" width="'.$w.'" height="48" fill="'.$this->e($palette['accent']).'"/>',
        ];

        $y = 96;

        if ($hasImage) {
            // A neutral image placeholder box: the template HAS an image, but
            // this preview deliberately does not fetch or rasterise it.
            $parts[] = '<rect x="48" y="'.$y.'" width="'.($w - 96).'" height="120" fill="'.$this->e($palette['accent']).'" opacity="0.18"/>';
            $parts[] = '<path d="M'.($w / 2 - 26).' '.($y + 78).'l30-38 22 27 14-16 22 27z" fill="'.$this->e($palette['accent']).'" opacity="0.55"/>';
            $parts[] = '<circle cx="'.($w / 2 - 46).'" cy="'.($y + 34).'" r="12" fill="'.$this->e($palette['accent']).'" opacity="0.55"/>';
            $y += 152;
        }

        if ($heading !== '') {
            $parts[] = '<text x="48" y="'.$y.'" font-family="Arial,Helvetica,sans-serif" font-size="30"'
                .' font-weight="bold" fill="'.$this->e($palette['text']).'">'
                .$this->e($this->truncate($heading, 30)).'</text>';
            $y += 34;
        }

        // One bar per real text block, width proportional to that block's
        // length — the bars are derived data, not decoration.
        foreach (array_slice($blocks, 0, 5) as $index => $block) {
            if ($index === 0 && $heading !== '' && $block === $heading) {
                continue;
            }

            $ratio = min(1.0, max(0.25, mb_strlen($block) / 90));
            $barWidth = (int) round(($w - 96) * $ratio);

            $parts[] = '<rect x="48" y="'.$y.'" width="'.$barWidth.'" height="12" rx="6" fill="'
                .$this->e($palette['text']).'" opacity="0.22"/>';

            $y += 24;

            if ($y > $h - 40) {
                break;
            }
        }

        $parts[] = '</svg>';

        return implode('', $parts);
    }

    /**
     * Dominant colours declared by the markup itself.
     *
     * background = the most frequent LIGHT colour (relative luminance ≥ 0.72)
     * accent     = the most frequent mid-tone colour (0.12 < luminance < 0.72)
     * text       = the darkest declared colour
     * Ties break on the hex value so the result never flaps between runs.
     * Anything the markup does not declare falls back to the card defaults.
     *
     * @return array{background: string, accent: string, text: string}
     */
    public function palette(string $html): array
    {
        $counts = [];

        // #rgb / #rrggbb
        preg_match_all('/#([0-9a-f]{3}|[0-9a-f]{6})\b/i', $html, $hexes);

        foreach ($hexes[1] as $hex) {
            $normalized = $this->normalizeHex($hex);
            $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
        }

        // rgb(r, g, b) / rgba(r, g, b, a)
        preg_match_all('/rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i', $html, $rgbs, PREG_SET_ORDER);

        foreach ($rgbs as $rgb) {
            $normalized = sprintf('#%02x%02x%02x', min(255, (int) $rgb[1]), min(255, (int) $rgb[2]), min(255, (int) $rgb[3]));
            $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
        }

        if ($counts === []) {
            return [
                'background' => self::DEFAULT_BACKGROUND,
                'accent' => self::DEFAULT_ACCENT,
                'text' => self::DEFAULT_TEXT,
            ];
        }

        // Stable ordering: frequency desc, then hex asc so ties never flap.
        uksort($counts, function (string $a, string $b) use ($counts): int {
            return [$counts[$b], $a] <=> [$counts[$a], $b];
        });

        $background = null;
        $accent = null;
        $text = null;
        $darkest = 2.0;

        foreach ($counts as $hex => $_) {
            $luminance = $this->luminance($hex);

            if ($luminance >= 0.72) {
                $background ??= $hex;
            } elseif ($luminance > 0.12) {
                // Near-black is body text, never a brand accent — hence the
                // floor. Everything between the two thresholds is the band
                // real brand colours live in.
                $accent ??= $hex;
            }

            if ($luminance < $darkest) {
                $darkest = $luminance;
                $text = $hex;
            }
        }

        return [
            'background' => $background ?? self::DEFAULT_BACKGROUND,
            'accent' => $accent ?? self::DEFAULT_ACCENT,
            'text' => $text ?? self::DEFAULT_TEXT,
        ];
    }

    /* ================================================================ *
     *  Markup helpers
     * ================================================================ */

    private function parse(?string $html): ?DOMDocument
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $doc = new DOMDocument;

        if (! @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html)) {
            return null;
        }

        return $doc;
    }

    /** Text of the first h1–h6, merge tags stripped. */
    private function heading(DOMDocument $doc): ?string
    {
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $node) {
                $text = $this->clean($node->textContent);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * Readable text blocks in document order (p, td, li, div-with-own-text).
     * Empty and merge-tag-only blocks are dropped.
     *
     * @return list<string>
     */
    private function textBlocks(DOMDocument $doc): array
    {
        $blocks = [];

        foreach (['h1', 'h2', 'h3', 'p', 'li', 'td', 'div'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                // Only count a node's OWN text so a wrapper <div>/<td> does
                // not repeat every descendant's copy.
                $own = '';

                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMText) {
                        $own .= $child->wholeText;
                    }
                }

                $text = $this->clean($own);

                if ($text !== '' && ! in_array($text, $blocks, true)) {
                    $blocks[] = $text;
                }

                if (count($blocks) >= 8) {
                    return $blocks;
                }
            }
        }

        return $blocks;
    }

    /** Collapse whitespace, entities and merge tags out of a text run. */
    private function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\{[A-Z][A-Z0-9_|]*\}/u', '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    private function truncate(string $text, int $chars): string
    {
        return mb_strlen($text) <= $chars ? $text : mb_substr($text, 0, $chars - 1).'…';
    }

    private function normalizeHex(string $hex): string
    {
        $hex = mb_strtolower($hex);

        if (mb_strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }

    /** Perceived relative luminance, 0 (black) … 1 (white). */
    private function luminance(string $hex): float
    {
        [$r, $g, $b] = [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];

        return (0.2126 * (int) $r + 0.7152 * (int) $g + 0.0722 * (int) $b) / 255;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
