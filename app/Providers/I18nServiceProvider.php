<?php

declare(strict_types=1);

namespace App\Providers;

use App\Filament\Admin\Pages\LocalePreferencePage;
use App\Http\Middleware\SetLocale;
use App\Services\I18n\Locales;
use App\Services\I18n\OverrideTranslationLoader;
use Filament\Navigation\MenuItem;
use Filament\PanelRegistry;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\ServiceProvider;

/**
 * WP-20 internationalisation wiring (spec §3.22 + §6.5):
 *
 * - decorates the translation loader so translation_overrides rows win over
 *   lang/ file strings (F-6.5-003);
 * - attaches {@see SetLocale} to the `web` group (public pages, auth module,
 *   Livewire) and to both Filament panels' auth stacks — the PanelRegistry
 *   mutation pattern from AuthModuleServiceProvider/SkinServiceProvider, so
 *   the WP-01 panel providers stay untouched (F-3.22-002 / F-6.5-005);
 * - adds the admin user-menu link to the locale preference page;
 * - injects dir="rtl" into both panels via a render hook when the active
 *   locale's language pack is flagged RTL (F-3.22-003 / F-6.5-006). The
 *   public + guest Blade layouts set the dir attribute directly.
 */
class I18nServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Override-aware translation loading (upgrade-safe: files stay
        // pristine, operator edits live in translation_overrides).
        $this->app->extend(
            'translation.loader',
            fn (Loader $loader): Loader => new OverrideTranslationLoader($loader),
        );

        $this->app->resolving(PanelRegistry::class, function (PanelRegistry $registry): void {
            foreach (['admin', 'customer'] as $panelId) {
                ($registry->all()[$panelId] ?? null)?->authMiddleware([SetLocale::class]);
            }

            // Per-admin-user language selection entry point (F-6.5-005) —
            // the Security/account pages belong to other work packages.
            ($registry->all()['admin'] ?? null)?->userMenuItems([
                MenuItem::make()
                    ->label(fn (): string => __('i18n.locale_page.menu'))
                    ->icon('heroicon-o-language')
                    ->url(fn (): string => LocalePreferencePage::getUrl()),
            ]);
        });
    }

    public function boot(): void
    {
        // Public frontend + auth module + Livewire update routes all run in
        // the `web` group; panel routes get the middleware via authMiddleware
        // above (SetLocale is idempotent when both apply).
        $this->app['router']->pushMiddlewareToGroup('web', SetLocale::class);

        // RTL for the panels (F-3.22-003): Filament owns the <html> tag, so
        // flip the direction attribute from a HEAD_END render hook — the
        // SkinServiceProvider injection precedent.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            function (): string {
                if (! Locales::isRtl(app()->getLocale())) {
                    return '';
                }

                return '<script data-i18n-dir="rtl">document.documentElement.setAttribute("dir", "rtl");</script>';
            },
        );
    }
}
