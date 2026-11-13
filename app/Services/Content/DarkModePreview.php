<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * Dark-mode preview (spec §2.3.3 F-2.3.3-027).
 *
 * WHAT THIS IS, precisely, because "dark mode preview" means three different
 * things in email and only one of them is reproducible outside a real client:
 *
 *  1. Clients that RESPECT the author's `@media (prefers-color-scheme: dark)`
 *     block (Apple Mail, iOS Mail). A parent page cannot force that media
 *     query on an iframe, so {@see simulate()} rewrites those blocks into
 *     unconditional rules — the author's own dark styles, actually applied.
 *  2. Clients that FORCE a colour inversion on light emails regardless of the
 *     author's wishes (Outlook.com, Gmail on Android). That is reproduced with
 *     the standard invert + hue-rotate transform, with images counter-inverted
 *     so photos are not turned into negatives.
 *  3. Pixel-accurate rendering per client, which needs a real rendering farm.
 *     That is still NOT what this class does — but it is no longer unavailable:
 *     WP-46 shipped F-2.3.5-006 as App\Services\Content\InboxPreview\*, a
 *     registry of adapters for Litmus and Email on Acid. Those produce real
 *     client screenshots and require the customer's own paid account with the
 *     vendor; without one the registry resolves NullProvider and says so. The
 *     simulation below remains what an install with no vendor account gets,
 *     and the UI caption still says which is which.
 */
class DarkModePreview
{
    public const MODE_AUTHOR = 'author';

    public const MODE_FORCED = 'forced';

    public function simulate(string $html, string $mode = self::MODE_FORCED): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $html = $mode === self::MODE_AUTHOR
            ? $this->unwrapAuthorDarkBlocks($html)
            : $html;

        return $this->injectStyle($html, $mode === self::MODE_AUTHOR
            ? $this->authorStyle()
            : $this->forcedStyle());
    }

    /**
     * Turn `@media (prefers-color-scheme: dark) { … }` into plain rules so the
     * author's own dark styles apply inside the preview iframe.
     */
    private function unwrapAuthorDarkBlocks(string $html): string
    {
        return (string) preg_replace_callback(
            '/@media[^{]*prefers-color-scheme\s*:\s*dark[^{]*\{(.*?)\}\s*\}/is',
            static fn (array $match): string => $match[1].'}',
            $html,
        );
    }

    private function authorStyle(): string
    {
        // Only the page surround is darkened; everything else is the author's.
        return 'html,body{background-color:#111827 !important;}';
    }

    private function forcedStyle(): string
    {
        return 'html{background-color:#111827 !important;}'
            .'body{filter:invert(1) hue-rotate(180deg);background-color:#ffffff !important;}'
            .'img,video,picture,[data-no-invert]{filter:invert(1) hue-rotate(180deg);}';
    }

    private function injectStyle(string $html, string $css): string
    {
        $style = '<style data-dark-preview>'.$css.'</style>';

        if (preg_match('/<\/head>/i', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = $match[0][1];

            return substr($html, 0, $at).$style.substr($html, $at);
        }

        return $style.$html;
    }
}
