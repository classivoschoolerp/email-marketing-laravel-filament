<?php

declare(strict_types=1);

namespace App\Services\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Throwable;

/**
 * Accessibility check for email bodies (spec §2.3.5 F-2.3.5-007 "alt text,
 * contrast").
 *
 * Returns structured findings — a stable machine `rule` key, a severity, the
 * offending markup excerpt, a human label and a fix hint — so the same output
 * drives the preflight checklist, the preview panel and (later) an export.
 *
 * ============================ THE RULES =============================
 *  error   img_missing_alt        <img> with no alt attribute at all.
 *  notice  img_empty_alt          alt="" on an image that does not look
 *                                 decorative — legal, but confirm it.
 *  notice  img_decorative_alt     a spacer/tracking pixel (≤ 3 px, or a
 *                                 spacer-ish filename) carrying real alt text;
 *                                 those should be alt="".
 *  error   link_no_text           <a> with no text and no image alt: a screen
 *                                 reader announces the raw URL.
 *  warning link_generic_text      link text that carries no meaning out of
 *                                 context ("click here", "read more", …).
 *  warning missing_lang           no <html lang="…">: screen readers guess the
 *                                 pronunciation language.
 *  warning heading_order          the first heading is not <h1>, or a level is
 *                                 skipped (h2 → h4).
 *  notice  layout_table           <table> with no <th>/<caption>/scope and no
 *                                 role="presentation": announced as a data
 *                                 table it is not.
 *  warning small_font             resolved font-size below MIN_FONT_PX.
 *  error   contrast_low           resolved foreground/background pair below the
 *                                 WCAG 2.1 AA ratio for that text size.
 *  notice  contrast_unresolved    a text run whose colours this checker cannot
 *                                 resolve — reported, never passed.
 *  notice  css_not_parsed         <style> selectors outside the simple subset
 *                                 below; their declarations were IGNORED, so
 *                                 the contrast verdict for elements they style
 *                                 may be wrong in either direction.
 *
 * ====================== WHAT CONTRAST CAN SEE =======================
 * This is a STATIC analysis of one HTML document. It resolves:
 *   - inline `style` attributes (the dominant form in email);
 *   - the presentational attributes email still uses: bgcolor, <font color>,
 *     <body text=…>;
 *   - <style> rules whose selector is exactly ONE of `tag`, `.class` or `#id`
 *     (comma-separated lists of those are fine). Applied tag → class → id →
 *     inline, which is CSS specificity order for that subset.
 *
 * It deliberately CANNOT see, and says so instead of passing:
 *   - descendant/attribute/pseudo selectors, @media and @supports blocks —
 *     every such rule is counted and surfaced as `css_not_parsed`;
 *   - external stylesheets (email clients strip them anyway);
 *   - background images, gradients and any colour with alpha < 1, because the
 *     composite depends on pixels this process never sees;
 *   - the client's own dark-mode inversion (that is F-2.3.3-027, DarkModePreview).
 *
 * Unresolved defaults are the CSS initial values a client would use: black
 * text on a white page. Where a declared colour exists but cannot be parsed,
 * the run becomes `contrast_unresolved` rather than falling back to the
 * default — a false pass is worse than an admission.
 */
final class AccessibilityChecker
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_NOTICE = 'notice';

    public const RULE_MISSING_LANG = 'missing_lang';

    public const RULE_IMG_MISSING_ALT = 'img_missing_alt';

    public const RULE_IMG_EMPTY_ALT = 'img_empty_alt';

    public const RULE_IMG_DECORATIVE_ALT = 'img_decorative_alt';

    public const RULE_LINK_NO_TEXT = 'link_no_text';

    public const RULE_LINK_GENERIC_TEXT = 'link_generic_text';

    public const RULE_HEADING_ORDER = 'heading_order';

    public const RULE_LAYOUT_TABLE = 'layout_table';

    public const RULE_SMALL_FONT = 'small_font';

    public const RULE_CONTRAST_LOW = 'contrast_low';

    public const RULE_CONTRAST_UNRESOLVED = 'contrast_unresolved';

    public const RULE_CSS_NOT_PARSED = 'css_not_parsed';

    /** Body text below this many CSS pixels is flagged (email convention). */
    public const MIN_FONT_PX = 12.0;

    /** CSS initial font size assumed for the document root. */
    public const BASE_FONT_PX = 16.0;

    /** An image at or below this many pixels on either axis is a spacer. */
    public const DECORATIVE_MAX_PX = 3;

    /** At most this many findings per rule; the rest are counted, not listed. */
    public const MAX_PER_RULE = 12;

    /** CSS initial colours — what a client paints when nobody said otherwise. */
    public const DEFAULT_TEXT = [0, 0, 0];

    public const DEFAULT_BACKGROUND = [255, 255, 255];

    /**
     * Link text that conveys nothing when read out of context (WCAG 2.4.4).
     *
     * @var list<string>
     */
    public const GENERIC_LINK_TEXT = [
        'click here', 'click', 'here', 'read more', 'learn more', 'more',
        'see more', 'find out more', 'this', 'this link', 'link', 'continue',
        'details', 'go', 'download',
    ];

    /** Filenames that mean "this image is a spacer, not content". */
    private const SPACER_HINTS = ['spacer', 'pixel', 'blank', 'transparent', 'clear', '1x1', 'shim', 'dot'];

    /** HTML 4 <font size="N"> in CSS pixels, N = 1..7. */
    private const FONT_SIZE_ATTRIBUTE_PX = [1 => 10.0, 2 => 13.0, 3 => 16.0, 4 => 18.0, 5 => 24.0, 6 => 32.0, 7 => 48.0];

    private const ABSOLUTE_KEYWORDS = [
        'xx-small' => 9.0, 'x-small' => 10.0, 'small' => 13.0, 'medium' => 16.0,
        'large' => 18.0, 'x-large' => 24.0, 'xx-large' => 32.0,
    ];

    /** @var array<string, list<array<string, string>>> */
    private array $tagRules = [];

    /** @var array<string, list<array<string, string>>> */
    private array $classRules = [];

    /** @var array<string, list<array<string, string>>> */
    private array $idRules = [];

    private int $skippedSelectors = 0;

    /** @var list<AccessibilityFinding> */
    private array $found = [];

    /** @var array<string, int> */
    private array $counts = [];

    private int $headingLevel = 0;

    public function __construct(private readonly ColorContrast $contrast) {}

    /**
     * Every finding, capped at {@see MAX_PER_RULE} per rule. The uncapped
     * tally is available from {@see counts()} after the same call.
     *
     * @return list<AccessibilityFinding>
     */
    public function check(string $html): array
    {
        $this->reset();

        if (trim($html) === '') {
            return [];
        }

        $this->documentLanguage($html);

        $dom = $this->parse($html);

        if ($dom === null) {
            return $this->found;
        }

        $this->collectStyleRules($dom);

        $body = $dom->getElementsByTagName('body')->item(0);

        $this->walk(
            $body ?? $dom->documentElement,
            [
                'color' => self::DEFAULT_TEXT,
                'colorReason' => null,
                'background' => self::DEFAULT_BACKGROUND,
                'backgroundReason' => null,
                'fontPx' => self::BASE_FONT_PX,
                'bold' => false,
            ],
        );

        if ($this->skippedSelectors > 0) {
            $this->add(
                self::RULE_CSS_NOT_PARSED,
                self::SEVERITY_NOTICE,
                '',
                ['selectors' => $this->skippedSelectors],
            );
        }

        return $this->found;
    }

    /**
     * Uncapped occurrences per rule from the last {@see check()} call.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * Severity tally for a finding list — what the preflight row shows.
     *
     * @param  list<AccessibilityFinding>  $findings
     * @return array{error: int, warning: int, notice: int}
     */
    public function summary(array $findings): array
    {
        $summary = [self::SEVERITY_ERROR => 0, self::SEVERITY_WARNING => 0, self::SEVERITY_NOTICE => 0];

        foreach ($findings as $finding) {
            $summary[$finding->severity] = ($summary[$finding->severity] ?? 0) + 1;
        }

        return $summary;
    }

    /** No error-level finding — the bar CampaignPreflight enforces. */
    public function passes(string $html): bool
    {
        foreach ($this->check($html) as $finding) {
            if ($finding->severity === self::SEVERITY_ERROR) {
                return false;
            }
        }

        return true;
    }

    /* ================================================================ *
     *  Document-level rules
     * ================================================================ */

    /**
     * Read from the SOURCE, not the DOM: DOMDocument synthesises an <html>
     * element for a fragment, and asserting against a synthesised element
     * would report a lang that the customer never wrote.
     */
    private function documentLanguage(string $html): void
    {
        if (preg_match('/<html\b[^>]*>/i', $html, $match) !== 1) {
            $this->add(self::RULE_MISSING_LANG, self::SEVERITY_WARNING, '');

            return;
        }

        if (preg_match('/\blang\s*=\s*["\']?[a-z]{2}/i', $match[0]) !== 1) {
            $this->add(self::RULE_MISSING_LANG, self::SEVERITY_WARNING, $this->truncate($match[0]));
        }
    }

    /* ================================================================ *
     *  Tree walk
     * ================================================================ */

    /**
     * @param  array{color: ?array, colorReason: ?string, background: ?array,
     *              backgroundReason: ?string, fontPx: float, bold: bool}  $inherited
     */
    private function walk(?DOMNode $node, array $inherited): void
    {
        if (! $node instanceof DOMElement) {
            return;
        }

        $tag = mb_strtolower($node->tagName);

        if (in_array($tag, ['head', 'style', 'script', 'title', 'meta', 'link'], true)) {
            return;
        }

        $declarations = $this->declarationsFor($node, $tag);

        // Not rendered ⇒ not an accessibility problem (this is also what keeps
        // the F-2.3.2-009 hidden preheader block out of the contrast report).
        if ($this->isHidden($node, $declarations)) {
            return;
        }

        $state = $this->applyDeclarations($inherited, $declarations, $tag);

        $this->elementRules($node, $tag, $state);

        if ($this->hasOwnText($node)) {
            $this->contrastRule($node, $state);
        }

        foreach ($node->childNodes as $child) {
            $this->walk($child, $state);
        }
    }

    /**
     * @param  array<string, string>  $declarations
     * @param  array<string, mixed>  $inherited
     * @return array<string, mixed>
     */
    private function applyDeclarations(array $inherited, array $declarations, string $tag): array
    {
        $state = $inherited;

        if (isset($declarations['color'])) {
            $parsed = $this->contrast->parse($declarations['color']);

            $state['color'] = $parsed;
            $state['colorReason'] = $parsed === null ? $this->reasonFor($declarations['color']) : null;
        }

        $background = $declarations['background-color'] ?? $declarations['background'] ?? null;

        if ($background !== null) {
            if ($this->contrast->isTransparent($background)) {
                // Transparent paints nothing: the parent's background shows.
            } elseif (preg_match('/url\(|gradient\(/i', $background) === 1) {
                $state['background'] = null;
                $state['backgroundReason'] = $this->reasonFor($background);
            } else {
                $parsed = $this->contrast->parse($this->firstColorToken($background));

                $state['background'] = $parsed;
                $state['backgroundReason'] = $parsed === null ? $this->reasonFor($background) : null;
            }
        }

        if (isset($declarations['font-size'])) {
            $size = $this->resolveFontSize($declarations['font-size'], (float) $inherited['fontPx']);

            if ($size !== null) {
                $state['fontPx'] = $size;
            }
        }

        if (in_array($tag, ['b', 'strong', 'th'], true)) {
            $state['bold'] = true;
        }

        if (isset($declarations['font-weight'])) {
            $weight = mb_strtolower(trim($declarations['font-weight']));

            $state['bold'] = $weight === 'bold' || $weight === 'bolder'
                || (is_numeric($weight) && (float) $weight >= 700);
        }

        // Headings are bold and larger by default in every client.
        if (preg_match('/^h([1-6])$/', $tag, $match) === 1 && ! isset($declarations['font-size'])) {
            $state['fontPx'] = [1 => 32.0, 2 => 24.0, 3 => 18.72, 4 => 16.0, 5 => 13.28, 6 => 10.72][(int) $match[1]];
            $state['bold'] = ! isset($declarations['font-weight']) || $state['bold'];
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function elementRules(DOMElement $node, string $tag, array $state): void
    {
        match ($tag) {
            'img' => $this->imageRules($node),
            'a' => $this->linkRules($node),
            'table' => $this->tableRules($node),
            default => null,
        };

        if (preg_match('/^h([1-6])$/', $tag, $match) === 1) {
            $this->headingRule($node, (int) $match[1]);
        }

        if ($this->hasOwnText($node) && (float) $state['fontPx'] < self::MIN_FONT_PX) {
            $this->add(
                self::RULE_SMALL_FONT,
                self::SEVERITY_WARNING,
                $this->excerpt($node),
                ['size_px' => round((float) $state['fontPx'], 2), 'minimum_px' => self::MIN_FONT_PX],
            );
        }
    }

    private function imageRules(DOMElement $node): void
    {
        $decorative = $this->looksDecorative($node);

        if (! $node->hasAttribute('alt')) {
            $this->add(
                $decorative ? self::RULE_IMG_DECORATIVE_ALT : self::RULE_IMG_MISSING_ALT,
                $decorative ? self::SEVERITY_NOTICE : self::SEVERITY_ERROR,
                $this->excerpt($node),
            );

            return;
        }

        $alt = trim($node->getAttribute('alt'));

        if ($alt === '') {
            if (! $decorative) {
                $this->add(self::RULE_IMG_EMPTY_ALT, self::SEVERITY_NOTICE, $this->excerpt($node));
            }

            return;
        }

        if ($decorative) {
            $this->add(self::RULE_IMG_DECORATIVE_ALT, self::SEVERITY_NOTICE, $this->excerpt($node), ['alt' => $alt]);
        }
    }

    private function linkRules(DOMElement $node): void
    {
        if (! $node->hasAttribute('href')) {
            return;
        }

        $text = $this->accessibleName($node);

        if ($text === '') {
            $this->add(self::RULE_LINK_NO_TEXT, self::SEVERITY_ERROR, $this->excerpt($node));

            return;
        }

        $normalised = trim(mb_strtolower((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text)), " \t\n\r\0\x0B.!:>»→-");

        if (in_array($normalised, self::GENERIC_LINK_TEXT, true)) {
            $this->add(self::RULE_LINK_GENERIC_TEXT, self::SEVERITY_WARNING, $this->excerpt($node), ['text' => $text]);
        }
    }

    private function tableRules(DOMElement $node): void
    {
        $role = mb_strtolower(trim($node->getAttribute('role')));

        if ($role === 'presentation' || $role === 'none') {
            return;
        }

        // A real data table announces itself: header cells, a caption, or
        // scope/headers attributes. Anything else is layout markup.
        if ($node->getElementsByTagName('th')->length > 0
            || $node->getElementsByTagName('caption')->length > 0) {
            return;
        }

        $this->add(self::RULE_LAYOUT_TABLE, self::SEVERITY_NOTICE, $this->openTag($node));
    }

    private function headingRule(DOMElement $node, int $level): void
    {
        $previous = $this->headingLevel;
        $this->headingLevel = $level;

        if ($previous === 0) {
            if ($level !== 1) {
                $this->add(self::RULE_HEADING_ORDER, self::SEVERITY_WARNING, $this->excerpt($node), [
                    'from' => 0,
                    'to' => $level,
                ]);
            }

            return;
        }

        if ($level > $previous + 1) {
            $this->add(self::RULE_HEADING_ORDER, self::SEVERITY_WARNING, $this->excerpt($node), [
                'from' => $previous,
                'to' => $level,
            ]);
        }
    }

    /** @param array<string, mixed> $state */
    private function contrastRule(DOMElement $node, array $state): void
    {
        $foreground = $state['color'];
        $background = $state['background'];

        if ($foreground === null || $background === null) {
            $this->add(self::RULE_CONTRAST_UNRESOLVED, self::SEVERITY_NOTICE, $this->excerpt($node), [
                'reason' => (string) ($foreground === null
                    ? ($state['colorReason'] ?? 'unresolved')
                    : ($state['backgroundReason'] ?? 'unresolved')),
            ]);

            return;
        }

        $ratio = $this->contrast->ratio($foreground, $background);
        $required = $this->contrast->required((float) $state['fontPx'], (bool) $state['bold']);

        if ($ratio + 1e-9 < $required) {
            $this->add(self::RULE_CONTRAST_LOW, self::SEVERITY_ERROR, $this->excerpt($node), [
                'ratio' => round($ratio, 2),
                'required' => $required,
                'foreground' => $this->hex($foreground),
                'background' => $this->hex($background),
                'size_px' => round((float) $state['fontPx'], 2),
            ]);
        }
    }

    /* ================================================================ *
     *  Declarations: presentational attributes → tag → class → id → inline
     * ================================================================ */

    /** @return array<string, string> */
    private function declarationsFor(DOMElement $node, string $tag): array
    {
        $declarations = $this->presentationalAttributes($node, $tag);

        foreach ($this->tagRules[$tag] ?? [] as $rule) {
            $declarations = array_merge($declarations, $rule);
        }

        foreach (preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [] as $class) {
            foreach ($this->classRules[mb_strtolower($class)] ?? [] as $rule) {
                $declarations = array_merge($declarations, $rule);
            }
        }

        foreach ($this->idRules[mb_strtolower(trim($node->getAttribute('id')))] ?? [] as $rule) {
            $declarations = array_merge($declarations, $rule);
        }

        return array_merge($declarations, $this->parseDeclarations($node->getAttribute('style')));
    }

    /** @return array<string, string> */
    private function presentationalAttributes(DOMElement $node, string $tag): array
    {
        $declarations = [];

        if ($node->hasAttribute('bgcolor')) {
            $declarations['background-color'] = $node->getAttribute('bgcolor');
        }

        if ($tag === 'font') {
            if ($node->hasAttribute('color')) {
                $declarations['color'] = $node->getAttribute('color');
            }

            if ($node->hasAttribute('size')) {
                $size = (int) $node->getAttribute('size');

                if (isset(self::FONT_SIZE_ATTRIBUTE_PX[$size])) {
                    $declarations['font-size'] = self::FONT_SIZE_ATTRIBUTE_PX[$size].'px';
                }
            }
        }

        if ($tag === 'body' && $node->hasAttribute('text')) {
            $declarations['color'] = $node->getAttribute('text');
        }

        return $declarations;
    }

    /** @return array<string, string> */
    private function parseDeclarations(string $css): array
    {
        $declarations = [];

        foreach (explode(';', $css) as $piece) {
            $position = mb_strpos($piece, ':');

            if ($position === false) {
                continue;
            }

            $property = mb_strtolower(trim(mb_substr($piece, 0, $position)));
            $value = trim((string) preg_replace('/!\s*important\s*$/i', '', trim(mb_substr($piece, $position + 1))));

            if ($property !== '' && $value !== '') {
                $declarations[$property] = $value;
            }
        }

        return $declarations;
    }

    /**
     * <style> blocks, restricted to the simple selector subset documented on
     * the class. Everything else increments {@see $skippedSelectors} and is
     * surfaced as `css_not_parsed`.
     */
    private function collectStyleRules(DOMDocument $dom): void
    {
        foreach ((new DOMXPath($dom))->query('//style') ?: [] as $style) {
            $css = (string) preg_replace('#/\*.*?\*/#s', '', $style->textContent);
            $length = strlen($css);
            $offset = 0;

            while ($offset < $length) {
                $brace = strpos($css, '{', $offset);

                if ($brace === false) {
                    break;
                }

                $selector = trim(substr($css, $offset, $brace - $offset));
                $close = $this->matchingBrace($css, $brace);

                if ($close === null) {
                    break;
                }

                $body = substr($css, $brace + 1, $close - $brace - 1);
                $offset = $close + 1;

                if ($selector === '' || $selector[0] === '@') {
                    // @media / @supports / @font-face — the whole block is
                    // ignored, and every selector inside it is counted so the
                    // customer is told the analysis was partial.
                    $this->skippedSelectors += max(1, substr_count($body, '{'));

                    continue;
                }

                $this->storeRule($selector, $this->parseDeclarations($body));
            }
        }
    }

    /** @param array<string, string> $declarations */
    private function storeRule(string $selector, array $declarations): void
    {
        if ($declarations === []) {
            return;
        }

        foreach (explode(',', $selector) as $single) {
            $single = mb_strtolower(trim($single));

            if (preg_match('/^([a-z][a-z0-9]*)$/', $single, $match) === 1) {
                $this->tagRules[$match[1]][] = $declarations;
            } elseif (preg_match('/^\.([-_a-z0-9]+)$/', $single, $match) === 1) {
                $this->classRules[$match[1]][] = $declarations;
            } elseif (preg_match('/^#([-_a-z0-9]+)$/', $single, $match) === 1) {
                $this->idRules[$match[1]][] = $declarations;
            } elseif ($single !== '') {
                $this->skippedSelectors++;
            }
        }
    }

    private function matchingBrace(string $css, int $open): ?int
    {
        $depth = 0;
        $length = strlen($css);

        for ($i = $open; $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /* ================================================================ *
     *  Helpers
     * ================================================================ */

    private function parse(string $html): ?DOMDocument
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            // Numeric entities keep UTF-8 (CJK, emoji) intact without the
            // mb_convert_encoding('HTML-ENTITIES') call PHP 8.2 deprecated.
            $encoded = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

            $loaded = $dom->loadHTML($encoded, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $dom : null;
    }

    /** @param array<string, string> $declarations */
    private function isHidden(DOMElement $node, array $declarations): bool
    {
        if ($node->hasAttribute('hidden') || mb_strtolower(trim($node->getAttribute('aria-hidden'))) === 'true') {
            return true;
        }

        $display = mb_strtolower(trim($declarations['display'] ?? ''));

        return $display === 'none';
    }

    /** True when the element itself holds visible text (not only children). */
    private function hasOwnText(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText && trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $child->wholeText)) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Text a screen reader would announce for a link. */
    private function accessibleName(DOMElement $node): string
    {
        $label = trim($node->getAttribute('aria-label'));

        if ($label !== '') {
            return $label;
        }

        $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $node->textContent));

        if ($text !== '') {
            return $text;
        }

        foreach ($node->getElementsByTagName('img') as $image) {
            $alt = trim($image->getAttribute('alt'));

            if ($alt !== '') {
                return $alt;
            }
        }

        return trim($node->getAttribute('title'));
    }

    private function looksDecorative(DOMElement $node): bool
    {
        foreach (['width', 'height'] as $attribute) {
            $raw = trim($node->getAttribute($attribute));

            if ($raw !== '' && is_numeric(rtrim($raw, 'px')) && (float) rtrim($raw, 'px') <= self::DECORATIVE_MAX_PX) {
                return true;
            }
        }

        $source = mb_strtolower($node->getAttribute('src'));

        foreach (self::SPACER_HINTS as $hint) {
            if ($source !== '' && str_contains($source, $hint)) {
                return true;
            }
        }

        return false;
    }

    /** First token of a `background` shorthand that could be a colour. */
    private function firstColorToken(string $value): string
    {
        $value = trim($value);

        if (preg_match('/(#[0-9a-f]{3,8}|(?:rgba?|hsla?)\([^)]*\))/i', $value, $match) === 1) {
            return $match[1];
        }

        $parts = preg_split('/\s+/', $value) ?: [];

        return (string) ($parts[0] ?? '');
    }

    private function resolveFontSize(string $value, float $parentPx): ?float
    {
        $value = mb_strtolower(trim($value));

        if (isset(self::ABSOLUTE_KEYWORDS[$value])) {
            return self::ABSOLUTE_KEYWORDS[$value];
        }

        if ($value === 'smaller') {
            return $parentPx / 1.2;
        }

        if ($value === 'larger') {
            return $parentPx * 1.2;
        }

        if (preg_match('/^(-?[0-9.]+)\s*(px|pt|pc|in|cm|mm|em|rem|%)$/', $value, $match) !== 1) {
            return null; // calc(), clamp(), vw, var() — not statically resolvable.
        }

        $number = (float) $match[1];

        return match ($match[2]) {
            'px' => $number,
            'pt' => $number * 96 / 72,
            'pc' => $number * 16,
            'in' => $number * 96,
            'cm' => $number * 96 / 2.54,
            'mm' => $number * 96 / 25.4,
            'em' => $number * $parentPx,
            'rem' => $number * self::BASE_FONT_PX,
            '%' => $number * $parentPx / 100,
            default => null,
        };
    }

    private function reasonFor(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return match (true) {
            $this->contrast->isTransparent($value) => 'transparent',
            str_contains($value, 'gradient(') => 'gradient',
            str_contains($value, 'url(') => 'background_image',
            str_contains($value, 'var(') => 'css_variable',
            str_contains($value, 'currentcolor') => 'current_color',
            preg_match('/^(?:rgba|hsla)\(/', $value) === 1 => 'alpha',
            preg_match('/^#[0-9a-f]{4}$|^#[0-9a-f]{8}$/', $value) === 1 => 'alpha',
            default => 'unknown_colour',
        };
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }

    private function openTag(DOMElement $node): string
    {
        $out = '<'.mb_strtolower($node->tagName);

        foreach ($node->attributes ?? [] as $attribute) {
            $out .= ' '.$attribute->nodeName.'="'.$attribute->nodeValue.'"';
        }

        return $this->truncate($out.'>');
    }

    private function excerpt(DOMElement $node): string
    {
        $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $node->textContent));

        return $this->truncate($text === '' ? $this->openTag($node) : $this->openTag($node).' '.$text);
    }

    private function truncate(string $value, int $limit = 160): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1).'…' : $value;
    }

    /** @param array<string, scalar> $context */
    private function add(string $rule, string $severity, string $excerpt, array $context = []): void
    {
        $this->counts[$rule] = ($this->counts[$rule] ?? 0) + 1;

        if ($this->counts[$rule] > self::MAX_PER_RULE) {
            return;
        }

        $this->found[] = new AccessibilityFinding(
            rule: $rule,
            severity: $severity,
            excerpt: $excerpt,
            label: __('accessibility.rules.'.$rule.'.label'),
            fix: __('accessibility.rules.'.$rule.'.fix'),
            context: $context,
        );
    }

    private function reset(): void
    {
        $this->tagRules = [];
        $this->classRules = [];
        $this->idRules = [];
        $this->skippedSelectors = 0;
        $this->found = [];
        $this->counts = [];
        $this->headingLevel = 0;
    }
}
