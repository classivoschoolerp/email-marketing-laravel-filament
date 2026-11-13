<?php

declare(strict_types=1);

namespace App\Support\Pwa;

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;

/**
 * §4.8 panel wiring (WP-40).
 *
 * F-4.8-002 — installability: the manifest link, the mobile viewport/theme
 * meta tags and the service-worker registration, injected into both panels'
 * <head>/<body> via render hooks (the SkinServiceProvider precedent).
 *
 * F-4.8-001 — the small amount of responsive CSS that cannot be expressed as
 * Tailwind classes on a blade of ours, because it targets Filament's OWN
 * markup: the table wrapper, the tab strip and the pagination bar. Shipping
 * it as an inline <style> keeps §4.8 free of a `npm run build` step, which
 * matters because a custom Filament theme would make every existing install
 * rebuild its assets before it could take an upgrade.
 *
 * Registered from routes/modules/analytics.php (D-41/D-51).
 */
final class PwaPanelExtensions
{
    public static function register(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_START,
            static fn (): string => self::head(),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            static fn (): string => self::registration(),
        );
    }

    private static function inPanel(): bool
    {
        return in_array(Filament::getCurrentPanel()?->getId(), ['admin', 'customer'], true);
    }

    private static function head(): string
    {
        if (! self::inPanel()) {
            return '';
        }

        $manifest = e(route('pwa.manifest'));
        $title = e(__('analytics.pwa.short_name'));

        return <<<HTML
            <link rel="manifest" href="{$manifest}">
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-status-bar-style" content="default">
            <meta name="apple-mobile-web-app-title" content="{$title}">
            <meta name="theme-color" content="#0f172a">
            <style data-emp-responsive="1">
                /* §4.8 F-4.8-001 — Filament's own markup, which no blade of
                   ours can reach with a utility class. */

                /* Tab strips (campaign report, analytics, list overview) must
                   scroll themselves instead of widening the document. */
                .fi-tabs { overflow-x: auto; flex-wrap: nowrap; scrollbar-width: thin; }
                .fi-tabs-item { white-space: nowrap; }

                /* Resource tables: horizontal scroll belongs to the table, not
                   the page. */
                .fi-ta-content { overflow-x: auto; -webkit-overflow-scrolling: touch; }

                @media (max-width: 640px) {
                    /* Filament renders the pagination bar as a single row; at
                       375px it overflows unless it may wrap. */
                    .fi-pagination { flex-wrap: wrap; row-gap: 0.5rem; }

                    /* Header action clusters on report/builder pages. */
                    .fi-header-actions, .fi-ac { flex-wrap: wrap; }

                    /* Modal + section padding is generous on desktop and
                       wasteful on a phone. */
                    .fi-modal-window { margin-left: 0.5rem; margin-right: 0.5rem; }

                    /* Nothing may push the document sideways. */
                    body { overflow-x: hidden; }
                }
            </style>
            HTML;
    }

    private static function registration(): string
    {
        if (! self::inPanel()) {
            return '';
        }

        $worker = e(route('pwa.service-worker'));

        // Registration is deliberately silent on failure: a browser without
        // service workers (or a plain-HTTP install) must still render the
        // panel normally, and an unusable install prompt is not an error the
        // operator can act on.
        return <<<HTML
            <script data-emp-pwa="1">
                if ('serviceWorker' in navigator) {
                    window.addEventListener('load', function () {
                        navigator.serviceWorker.register('{$worker}', { scope: '/' }).catch(function () {});
                    });
                }
            </script>
            HTML;
    }
}
