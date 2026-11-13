<?php

declare(strict_types=1);

namespace App\Support\Templates;

/**
 * Dark-mode styles for the generated email (spec §2.5.4 F-2.5.4-013, WP-42).
 *
 * THE HALF THAT WAS MISSING. App\Services\Content\DarkModePreview simulates
 * dark mode in the panel; nothing emitted dark CSS into the HTML that is
 * actually SENT. This class is that emitter, and it is the exact sibling of
 * {@see MobileVisibility}: the builder marks a block with a class, and this
 * helper guarantees the rule that acts on the class travels with the saved
 * markup — because, like the hide-on-mobile query, a `prefers-color-scheme`
 * rule CANNOT be inlined onto an element and must live in a <style> block.
 *
 * Per-block overrides are encoded in the class name itself:
 *
 *      class="dm-bg-111827"   → background-color:#111827 in dark mode
 *      class="dm-fg-f9fafb"   → color:#f9fafb            in dark mode
 *
 * A class name that carries its own value is what makes this idempotent and
 * builder-agnostic: re-saving never renumbers anything, an imported template
 * that already uses the convention works untouched, and both builders (and a
 * paste of raw HTML) produce the same result with no shared state.
 *
 * CLIENT SUPPORT — the honest caveat, same posture as MobileVisibility:
 *   honours @media (prefers-color-scheme:dark) → Apple Mail, iOS Mail,
 *     Outlook for macOS/iOS/Android, Thunderbird, HEY;
 *   FORCES its own inversion and ignores the author → Outlook.com and
 *     Gmail on Android. Those clients tag the elements they recolour with
 *     `data-ogsc`/`data-ogsb`, so the same declarations are additionally
 *     emitted behind those attribute selectors — a documented best effort,
 *     not a guarantee;
 *   no dark handling at all → Outlook 2007–2019 for Windows and any client
 *     that strips <style>. There the email renders exactly as it does today,
 *     which is the safe failure mode.
 */
final class DarkModeStyles
{
    /** Class prefix for a per-block dark BACKGROUND colour. */
    public const BG_PREFIX = 'dm-bg-';

    /** Class prefix for a per-block dark TEXT colour. */
    public const FG_PREFIX = 'dm-fg-';

    /** Marks the emitted <style> block so injection stays idempotent. */
    public const MARKER = 'data-dark-mode';

    /** `#111827` (or `111827`) → `dm-bg-111827`. */
    public static function bgClass(string $hex): string
    {
        return self::BG_PREFIX.self::hex($hex);
    }

    /** `#F9FAFB` (or `f9fafb`) → `dm-fg-f9fafb`. */
    public static function fgClass(string $hex): string
    {
        return self::FG_PREFIX.self::hex($hex);
    }

    /**
     * The distinct dark colours the markup actually asks for.
     *
     * @return array{bg: list<string>, fg: list<string>} six-digit lowercase hexes, sorted
     */
    public static function used(?string $html): array
    {
        $out = ['bg' => [], 'fg' => []];

        if ($html === null || $html === '') {
            return $out;
        }

        foreach (['bg' => self::BG_PREFIX, 'fg' => self::FG_PREFIX] as $slot => $prefix) {
            preg_match_all('/\b'.preg_quote($prefix, '/').'([0-9a-f]{6})\b/i', $html, $matches);

            $values = array_values(array_unique(array_map(
                static fn (string $hex): string => mb_strtolower($hex),
                $matches[1],
            )));

            sort($values);

            $out[$slot] = $values;
        }

        return $out;
    }

    /** Does the markup use any per-block dark override? */
    public static function isUsed(?string $html): bool
    {
        $used = self::used($html);

        return $used['bg'] !== [] || $used['fg'] !== [];
    }

    /** Is the emitted style block already present? */
    public static function hasStyleBlock(?string $html): bool
    {
        return $html !== null && $html !== '' && str_contains($html, self::MARKER);
    }

    /**
     * The exact CSS emitted for a set of colours. Deterministic: colours are
     * sorted, so two saves of the same document produce byte-identical CSS.
     *
     * @param  list<string>  $backgrounds  six-digit lowercase hexes
     * @param  list<string>  $foregrounds  six-digit lowercase hexes
     */
    public static function css(array $backgrounds, array $foregrounds): string
    {
        $declarations = '';

        foreach ($backgrounds as $hex) {
            $declarations .= '.'.self::BG_PREFIX.$hex.'{background-color:#'.$hex.'!important;}';
        }

        foreach ($foregrounds as $hex) {
            $declarations .= '.'.self::FG_PREFIX.$hex.'{color:#'.$hex.'!important;}';
        }

        // Outlook.com / Gmail-Android force their own inversion and never run
        // the media query; they tag what they recoloured instead.
        $forced = '';

        foreach ($backgrounds as $hex) {
            $forced .= '[data-ogsb] .'.self::BG_PREFIX.$hex.'{background-color:#'.$hex.'!important;}';
        }

        foreach ($foregrounds as $hex) {
            $forced .= '[data-ogsc] .'.self::FG_PREFIX.$hex.'{color:#'.$hex.'!important;}';
        }

        return ':root{color-scheme:light dark;supported-color-schemes:light dark;}'
            .'@media (prefers-color-scheme:dark){'.$declarations.'}'
            .$forced;
    }

    /** The <style> element written into the document. */
    public static function styleTag(string $css): string
    {
        return '<style type="text/css" '.self::MARKER.'>'.$css.'</style>';
    }

    /**
     * The two meta tags every dark-capable client reads to learn that the
     * message opts in to dark rendering rather than being force-inverted.
     */
    public static function metaTags(): string
    {
        return '<meta name="color-scheme" content="light dark">'
            .'<meta name="supported-color-schemes" content="light dark">';
    }

    /**
     * Guarantee the dark rules accompany the classes.
     *
     * Injection order, first match wins — identical to MobileVisibility:
     *   1. before </head>  — the correct place, and where the meta tags belong;
     *   2. after <body …>  — documents without a head still get the rules;
     *   3. prepended       — fragments (a builder may emit a bare snippet).
     * Markup that carries no dark class is returned untouched, so the emitter
     * never bloats an email that does not use the feature; markup that already
     * carries the block is returned untouched too, so repeated autosaves never
     * stack duplicate <style> elements.
     */
    public static function apply(?string $html): ?string
    {
        if ($html === null || $html === '' || self::hasStyleBlock($html)) {
            return $html;
        }

        $used = self::used($html);

        if ($used['bg'] === [] && $used['fg'] === []) {
            return $html;
        }

        $style = self::styleTag(self::css($used['bg'], $used['fg']));

        if (preg_match('/<\/head\s*>/i', $html) === 1) {
            return (string) preg_replace('/<\/head\s*>/i', self::metaTags().$style.'</head>', $html, 1);
        }

        if (preg_match('/<body\b[^>]*>/i', $html, $match) === 1) {
            return (string) preg_replace(
                '/'.preg_quote($match[0], '/').'/',
                $match[0].$style,
                $html,
                1,
            );
        }

        return $style.$html;
    }

    /** Normalise `#RRGGBB`, `RRGGBB`, `#RGB` or `RGB` to six lowercase hex digits. */
    private static function hex(string $value): string
    {
        $value = mb_strtolower(ltrim(trim($value), '#'));

        if (preg_match('/^[0-9a-f]{3}$/', $value) === 1) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        return preg_match('/^[0-9a-f]{6}$/', $value) === 1 ? $value : '000000';
    }
}
