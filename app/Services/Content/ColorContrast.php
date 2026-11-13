<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * WCAG 2.x colour maths (spec §2.3.5 F-2.3.5-007 "contrast"), written out in
 * full because a contrast number on a compliance report is a factual claim.
 *
 * RELATIVE LUMINANCE — WCAG 2.1 definition, verbatim:
 *
 *     L = 0.2126 R + 0.7152 G + 0.0722 B
 *
 * where each of R, G, B is the sRGB channel linearised from its 8-bit value
 * c₈ as
 *
 *     cs = c₈ / 255
 *     c  = cs / 12.92                    when cs ≤ 0.03928
 *     c  = ((cs + 0.055) / 1.055)^2.4    otherwise
 *
 * The 0.03928 knee (rather than the 0.04045 of the IEC sRGB standard) is what
 * WCAG 2.1 §"relative luminance" actually specifies; the difference is below
 * one 8-bit step and using the other value would put this implementation out
 * of agreement with every published conformance checker.
 *
 * CONTRAST RATIO — WCAG 2.1 definition:
 *
 *     ratio = (L_lighter + 0.05) / (L_darker + 0.05)
 *
 * which is 21 for black on white and 1 for any colour on itself.
 *
 * THRESHOLDS (Level AA, SC 1.4.3): 4.5:1 for normal text, 3:1 for LARGE text,
 * where large means ≥ 24 px, or ≥ 18.66 px when bold (WCAG states 18 pt and
 * 14 pt bold; at the CSS reference 96 dpi that is 24 px and 18.6666… px).
 *
 * WHAT PARSING CAN AND CANNOT RESOLVE. {@see parse()} understands #rgb,
 * #rgba, #rrggbb, #rrggbbaa, rgb()/rgba(), hsl()/hsla() and the CSS named
 * colours. It returns NULL — never a guess — for:
 *   - any colour with alpha < 1 (the composite depends on everything painted
 *     underneath, which static analysis of one document cannot know);
 *   - `transparent`, `currentColor`, `inherit`, CSS variables;
 *   - gradients, `url(...)` background images and unknown names.
 * A null propagates to the caller as "unresolved", which AccessibilityChecker
 * reports as its own finding rather than as a pass.
 */
final class ColorContrast
{
    /** WCAG 2.1 AA minimum for normal-size text. */
    public const AA_NORMAL = 4.5;

    /** WCAG 2.1 AA minimum for large text (≥ 24 px, or ≥ 18.66 px bold). */
    public const AA_LARGE = 3.0;

    /** Large-text boundary in CSS pixels (18 pt at 96 dpi). */
    public const LARGE_PX = 24.0;

    /** Large-text boundary in CSS pixels when bold (14 pt at 96 dpi). */
    public const LARGE_BOLD_PX = 18.6667;

    /**
     * The CSS Color Level 4 named colours. Data, not judgement: an unknown
     * name resolves to null so it is reported unresolved instead of guessed.
     *
     * @var array<string, string>
     */
    public const NAMED = [
        'aliceblue' => 'f0f8ff', 'antiquewhite' => 'faebd7', 'aqua' => '00ffff',
        'aquamarine' => '7fffd4', 'azure' => 'f0ffff', 'beige' => 'f5f5dc',
        'bisque' => 'ffe4c4', 'black' => '000000', 'blanchedalmond' => 'ffebcd',
        'blue' => '0000ff', 'blueviolet' => '8a2be2', 'brown' => 'a52a2a',
        'burlywood' => 'deb887', 'cadetblue' => '5f9ea0', 'chartreuse' => '7fff00',
        'chocolate' => 'd2691e', 'coral' => 'ff7f50', 'cornflowerblue' => '6495ed',
        'cornsilk' => 'fff8dc', 'crimson' => 'dc143c', 'cyan' => '00ffff',
        'darkblue' => '00008b', 'darkcyan' => '008b8b', 'darkgoldenrod' => 'b8860b',
        'darkgray' => 'a9a9a9', 'darkgreen' => '006400', 'darkgrey' => 'a9a9a9',
        'darkkhaki' => 'bdb76b', 'darkmagenta' => '8b008b', 'darkolivegreen' => '556b2f',
        'darkorange' => 'ff8c00', 'darkorchid' => '9932cc', 'darkred' => '8b0000',
        'darksalmon' => 'e9967a', 'darkseagreen' => '8fbc8f', 'darkslateblue' => '483d8b',
        'darkslategray' => '2f4f4f', 'darkslategrey' => '2f4f4f', 'darkturquoise' => '00ced1',
        'darkviolet' => '9400d3', 'deeppink' => 'ff1493', 'deepskyblue' => '00bfff',
        'dimgray' => '696969', 'dimgrey' => '696969', 'dodgerblue' => '1e90ff',
        'firebrick' => 'b22222', 'floralwhite' => 'fffaf0', 'forestgreen' => '228b22',
        'fuchsia' => 'ff00ff', 'gainsboro' => 'dcdcdc', 'ghostwhite' => 'f8f8ff',
        'gold' => 'ffd700', 'goldenrod' => 'daa520', 'gray' => '808080',
        'green' => '008000', 'greenyellow' => 'adff2f', 'grey' => '808080',
        'honeydew' => 'f0fff0', 'hotpink' => 'ff69b4', 'indianred' => 'cd5c5c',
        'indigo' => '4b0082', 'ivory' => 'fffff0', 'khaki' => 'f0e68c',
        'lavender' => 'e6e6fa', 'lavenderblush' => 'fff0f5', 'lawngreen' => '7cfc00',
        'lemonchiffon' => 'fffacd', 'lightblue' => 'add8e6', 'lightcoral' => 'f08080',
        'lightcyan' => 'e0ffff', 'lightgoldenrodyellow' => 'fafad2', 'lightgray' => 'd3d3d3',
        'lightgreen' => '90ee90', 'lightgrey' => 'd3d3d3', 'lightpink' => 'ffb6c1',
        'lightsalmon' => 'ffa07a', 'lightseagreen' => '20b2aa', 'lightskyblue' => '87cefa',
        'lightslategray' => '778899', 'lightslategrey' => '778899', 'lightsteelblue' => 'b0c4de',
        'lightyellow' => 'ffffe0', 'lime' => '00ff00', 'limegreen' => '32cd32',
        'linen' => 'faf0e6', 'magenta' => 'ff00ff', 'maroon' => '800000',
        'mediumaquamarine' => '66cdaa', 'mediumblue' => '0000cd', 'mediumorchid' => 'ba55d3',
        'mediumpurple' => '9370db', 'mediumseagreen' => '3cb371', 'mediumslateblue' => '7b68ee',
        'mediumspringgreen' => '00fa9a', 'mediumturquoise' => '48d1cc', 'mediumvioletred' => 'c71585',
        'midnightblue' => '191970', 'mintcream' => 'f5fffa', 'mistyrose' => 'ffe4e1',
        'moccasin' => 'ffe4b5', 'navajowhite' => 'ffdead', 'navy' => '000080',
        'oldlace' => 'fdf5e6', 'olive' => '808000', 'olivedrab' => '6b8e23',
        'orange' => 'ffa500', 'orangered' => 'ff4500', 'orchid' => 'da70d6',
        'palegoldenrod' => 'eee8aa', 'palegreen' => '98fb98', 'paleturquoise' => 'afeeee',
        'palevioletred' => 'db7093', 'papayawhip' => 'ffefd5', 'peachpuff' => 'ffdab9',
        'peru' => 'cd853f', 'pink' => 'ffc0cb', 'plum' => 'dda0dd',
        'powderblue' => 'b0e0e6', 'purple' => '800080', 'rebeccapurple' => '663399',
        'red' => 'ff0000', 'rosybrown' => 'bc8f8f', 'royalblue' => '4169e1',
        'saddlebrown' => '8b4513', 'salmon' => 'fa8072', 'sandybrown' => 'f4a460',
        'seagreen' => '2e8b57', 'seashell' => 'fff5ee', 'sienna' => 'a0522d',
        'silver' => 'c0c0c0', 'skyblue' => '87ceeb', 'slateblue' => '6a5acd',
        'slategray' => '708090', 'slategrey' => '708090', 'snow' => 'fffafa',
        'springgreen' => '00ff7f', 'steelblue' => '4682b4', 'tan' => 'd2b48c',
        'teal' => '008080', 'thistle' => 'd8bfd8', 'tomato' => 'ff6347',
        'turquoise' => '40e0d0', 'violet' => 'ee82ee', 'wheat' => 'f5deb3',
        'white' => 'ffffff', 'whitesmoke' => 'f5f5f5', 'yellow' => 'ffff00',
        'yellowgreen' => '9acd32',
    ];

    /**
     * Parse a CSS colour to 8-bit sRGB, or NULL when it cannot be resolved
     * statically (see the class docblock for the exhaustive list of nulls).
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    public function parse(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        if (isset(self::NAMED[$value])) {
            return $this->fromHex(self::NAMED[$value]);
        }

        if ($value[0] === '#') {
            return $this->fromHex(substr($value, 1));
        }

        if (preg_match('/^rgba?\(([^)]*)\)$/', $value, $match) === 1) {
            return $this->fromChannels($match[1], hsl: false);
        }

        if (preg_match('/^hsla?\(([^)]*)\)$/', $value, $match) === 1) {
            return $this->fromChannels($match[1], hsl: true);
        }

        // transparent / currentcolor / inherit / var(--x) / gradients / url().
        return null;
    }

    /** True for a value that explicitly paints nothing (so the parent shows). */
    public function isTransparent(?string $value): bool
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === 'transparent') {
            return true;
        }

        // rgba(…, 0) / hsla(…, 0) — fully transparent, the parent shows through.
        if (preg_match('/^(?:rgba|hsla)\(([^)]*)\)$/', $value, $match) === 1) {
            $parts = preg_split('/[\s,\/]+/', trim($match[1])) ?: [];

            return count($parts) === 4 && (float) $this->alpha($parts[3]) === 0.0;
        }

        return false;
    }

    /**
     * WCAG relative luminance of an 8-bit sRGB triplet.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function relativeLuminance(array $rgb): float
    {
        $linear = [];

        foreach ($rgb as $channel) {
            $cs = max(0, min(255, $channel)) / 255;
            $linear[] = $cs <= 0.03928 ? $cs / 12.92 : (($cs + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /**
     * WCAG contrast ratio, always ≥ 1 (the order of the arguments is
     * irrelevant, exactly as the definition intends).
     *
     * @param  array{0: int, 1: int, 2: int}  $a
     * @param  array{0: int, 1: int, 2: int}  $b
     */
    public function ratio(array $a, array $b): float
    {
        $la = $this->relativeLuminance($a);
        $lb = $this->relativeLuminance($b);

        $lighter = max($la, $lb);
        $darker = min($la, $lb);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /** The AA minimum for text of this size/weight (SC 1.4.3). */
    public function required(float $fontPx, bool $bold): float
    {
        if ($fontPx >= self::LARGE_PX || ($bold && $fontPx >= self::LARGE_BOLD_PX)) {
            return self::AA_LARGE;
        }

        return self::AA_NORMAL;
    }

    /* ---------------------------------------------------------------- */

    /** @return array{0: int, 1: int, 2: int}|null */
    private function fromHex(string $hex): ?array
    {
        $hex = trim($hex);

        if (preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
            return null;
        }

        $length = strlen($hex);

        if ($length === 3 || $length === 4) {
            // #rgb / #rgba — each digit doubled.
            if ($length === 4 && $hex[3] !== 'f') {
                return null; // alpha < 1: unresolvable (class docblock).
            }

            return [
                (int) hexdec($hex[0].$hex[0]),
                (int) hexdec($hex[1].$hex[1]),
                (int) hexdec($hex[2].$hex[2]),
            ];
        }

        if ($length === 6 || $length === 8) {
            if ($length === 8 && mb_strtolower(substr($hex, 6, 2)) !== 'ff') {
                return null; // alpha < 1.
            }

            return [
                (int) hexdec(substr($hex, 0, 2)),
                (int) hexdec(substr($hex, 2, 2)),
                (int) hexdec(substr($hex, 4, 2)),
            ];
        }

        return null;
    }

    /**
     * rgb()/rgba()/hsl()/hsla() argument list, both the comma and the modern
     * space/slash syntax.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function fromChannels(string $arguments, bool $hsl): ?array
    {
        $parts = preg_split('/[\s,\/]+/', trim($arguments)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));

        if (count($parts) < 3) {
            return null;
        }

        if (count($parts) >= 4 && abs($this->alpha($parts[3]) - 1.0) > 1e-9) {
            return null; // alpha < 1: unresolvable (class docblock).
        }

        if (! $hsl) {
            $rgb = [];

            foreach (array_slice($parts, 0, 3) as $part) {
                if (! is_numeric(rtrim($part, '%'))) {
                    return null;
                }

                $rgb[] = str_ends_with($part, '%')
                    ? (int) round((float) rtrim($part, '%') * 255 / 100)
                    : (int) round((float) $part);
            }

            return [max(0, min(255, $rgb[0])), max(0, min(255, $rgb[1])), max(0, min(255, $rgb[2]))];
        }

        $hue = (float) preg_replace('/deg$/', '', $parts[0]);
        $saturation = (float) rtrim($parts[1], '%') / 100;
        $lightness = (float) rtrim($parts[2], '%') / 100;

        return $this->hslToRgb($hue, $saturation, $lightness);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function hslToRgb(float $h, float $s, float $l): array
    {
        $h = fmod(fmod($h, 360) + 360, 360) / 360;
        $s = max(0.0, min(1.0, $s));
        $l = max(0.0, min(1.0, $l));

        if ($s === 0.0) {
            $grey = (int) round($l * 255);

            return [$grey, $grey, $grey];
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        $channel = static function (float $t) use ($p, $q): int {
            $t = fmod(fmod($t, 1.0) + 1.0, 1.0);

            $value = match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };

            return (int) round($value * 255);
        };

        return [$channel($h + 1 / 3), $channel($h), $channel($h - 1 / 3)];
    }

    private function alpha(string $raw): float
    {
        $raw = trim($raw);

        if (str_ends_with($raw, '%')) {
            return (float) rtrim($raw, '%') / 100;
        }

        return (float) $raw;
    }
}
