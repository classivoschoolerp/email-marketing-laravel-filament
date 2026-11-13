<?php

declare(strict_types=1);

namespace App\Support\Templates;

/**
 * Mobile-specific styling / hide-on-mobile (spec §2.5.4 F-2.5.4-006).
 *
 * The builder marks a block with the `hide-mobile` class; the rule that acts
 * on it is a real responsive media query, which — unlike every other style in
 * an email — CANNOT be inlined onto the element. It has to live in a <style>
 * block in the document head, so this helper is the single place that
 * guarantees the block exists in the SAVED html, no matter which builder,
 * importer or paste produced the markup.
 *
 * CLIENT SUPPORT — the honest caveat (also documented in the builder JS):
 * media queries are honoured by iOS Mail, Apple Mail, Gmail app (with a
 * <style> block in the head), Outlook for iOS/Android and most webmail.
 * They are IGNORED by Outlook 2007–2019 for Windows (Word engine), by
 * Gmail's older "non-clipped" desktop paths for some accounts, and by any
 * client that strips <style>. In those clients a hide-on-mobile block simply
 * stays VISIBLE — which is the safe failure mode, and the reason this feature
 * must never be used to hide legally required content such as the
 * unsubscribe link or the postal address.
 */
final class MobileVisibility
{
    /** Class the builder puts on a block that should disappear on phones. */
    public const HIDE_CLASS = 'hide-mobile';

    /** Breakpoint in pixels — the industry-standard email phone breakpoint. */
    public const BREAKPOINT = 600;

    /** The exact rule emitted into the document. */
    public static function mediaQuery(): string
    {
        return '@media only screen and (max-width:'.self::BREAKPOINT.'px){'
            .'.'.self::HIDE_CLASS.'{display:none!important;'
            .'max-height:0!important;overflow:hidden!important;'
            .'mso-hide:all;}'
            .'}';
    }

    /** Does the markup use the hide-on-mobile class on any element? */
    public static function isUsed(?string $html): bool
    {
        if ($html === null || $html === '') {
            return false;
        }

        return preg_match(
            '/\bclass\s*=\s*(["\'])[^"\']*\b'.preg_quote(self::HIDE_CLASS, '/').'\b[^"\']*\1/i',
            $html,
        ) === 1;
    }

    /** Is the media query already present (any whitespace variant)? */
    public static function hasMediaQuery(?string $html): bool
    {
        if ($html === null || $html === '') {
            return false;
        }

        return preg_match(
            '/@media[^{]*max-width\s*:\s*'.self::BREAKPOINT.'px[^{]*\{[^}]*\.'
            .preg_quote(self::HIDE_CLASS, '/').'\b/i',
            $html,
        ) === 1;
    }

    /**
     * Guarantee the media query accompanies the class.
     *
     * Injection order, first match wins:
     *   1. before </head>  — the correct place, and where Gmail keeps it;
     *   2. after <body …>  — documents without a head still render it;
     *   3. prepended       — fragments (the builder can emit a bare snippet).
     * Idempotent: markup that already carries the rule is returned untouched,
     * so repeated autosaves never stack duplicate <style> blocks.
     */
    public static function apply(?string $html): ?string
    {
        if ($html === null || $html === '' || ! self::isUsed($html) || self::hasMediaQuery($html)) {
            return $html;
        }

        $style = '<style type="text/css">'.self::mediaQuery().'</style>';

        if (preg_match('/<\/head\s*>/i', $html) === 1) {
            return (string) preg_replace('/<\/head\s*>/i', $style.'</head>', $html, 1);
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
}
