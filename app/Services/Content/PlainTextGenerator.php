<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * HTML → plain-text conversion for the auto-generated text part of a
 * campaign (spec §2.3.3 F-2.3.3-006). Link targets are preserved as
 * "anchor text (url)", block elements become line breaks, list items get a
 * "- " marker. UTF-8 safe (CJK/emoji content passes through untouched, per
 * docs/ARCHITECTURE.md utf8mb4 rule). Merge tags like {UNSUBSCRIBE_URL} survive as-is
 * so the text part stays personalizable.
 */
class PlainTextGenerator
{
    public function generate(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $text = $html;

        // Drop non-content blocks entirely.
        $text = (string) preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#isu', '', $text);
        $text = (string) preg_replace('#<!--.*?-->#su', '', $text);

        // Links: keep the destination — "text (url)". Anchors whose href is
        // empty, an anchor fragment, or identical to the text keep text only.
        $text = (string) preg_replace_callback(
            '#<a\b[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)</a>#isu',
            function (array $m): string {
                $href = trim($m[1]);
                $label = trim(strip_tags($m[2]));

                if ($href === '' || str_starts_with($href, '#') || $href === $label) {
                    return $label;
                }

                return $label === '' ? $href : $label.' ('.$href.')';
            },
            $text,
        );

        // Structural spacing before stripping tags: paragraphs/headings end
        // with a blank line, table rows and list containers with a single
        // break, list items get a "- " marker.
        $text = (string) preg_replace('#<(br|hr)\s*/?>#iu', "\n", $text);
        $text = (string) preg_replace('#<li\b[^>]*>#iu', "\n- ", $text);
        $text = (string) preg_replace('#</li>#iu', '', $text);
        $text = (string) preg_replace('#</(p|blockquote|h[1-6])>#iu', "\n\n", $text);
        $text = (string) preg_replace('#</(div|tr|table|ul|ol)>#iu', "\n", $text);
        $text = (string) preg_replace('#<(h[1-6])\b[^>]*>#iu', "\n", $text);
        $text = (string) preg_replace('#</t[dh]>#iu', ' ', $text);

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace: NBSP → space, collapse runs, trim lines,
        // cap consecutive blank lines at one.
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/ ?\n ?/u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }
}
