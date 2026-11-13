<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\Enforce2fa;
use App\Services\Branding\BrandContext;
use App\Services\Themes\ThemeCss;
use App\Services\Themes\ThemeResolver;
use App\Settings\SkinSettings;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * WP-05 panel customisation wiring (spec §3.25.6 + §3.25.8):
 *
 * - injects the admin/customer custom CSS & JS from the skin settings into
 *   the panels via Filament render hooks (F-3.25.8-002) — the frontend area
 *   is rendered by the themes work package through the settings reader;
 * - attaches the {@see Enforce2fa} middleware to both panels' auth stack
 *   (F-3.25.6-002), mutating the registered panels the same way
 *   {@see AuthModuleServiceProvider} does — the WP-01 panel providers stay
 *   untouched.
 */
class SkinServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->resolving(PanelRegistry::class, function (PanelRegistry $registry): void {
            foreach (['admin', 'customer'] as $panelId) {
                ($registry->all()[$panelId] ?? null)?->authMiddleware([Enforce2fa::class]);
            }
        });
    }

    public function boot(): void
    {
        // WP-38 (§6.3 F-6.3-001/002/006): the theme palette goes FIRST so the
        // operator's §3.25.8 custom CSS below still wins — a hand-written
        // override must beat a theme, never the other way round.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => $this->themeStyles(),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => $this->inject('custom_css', 'style'),
        );

        // WP-38 (§4.7 F-4.7-001): the brand logo above the panel navigation.
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_NAV_START,
            fn (): string => $this->brandLogo(),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => $this->inject('custom_js', 'script'),
        );
    }

    /**
     * WP-38 §6.3: the active theme's CSS custom properties for the panel
     * currently being rendered, resolved per SIGNED-IN USER (F-6.3-002) and
     * then per brand and per install setting — see ThemeResolver.
     */
    private function themeStyles(): string
    {
        $area = $this->currentArea();

        if ($area === null) {
            return '';
        }

        try {
            $account = Filament::auth()->user();
            $theme = app(ThemeResolver::class)->forArea($area, $account);
            $brand = app(BrandContext::class)->current();

            return app(ThemeCss::class)->styleTag($theme, $brand, $area);
        } catch (Throwable) {
            // Pre-migration / installer boot: render unthemed rather than 500.
            return '';
        }
    }

    /**
     * WP-38 §4.7 F-4.7-001/003: the installation's (or the brand's) own logo
     * in both panels. Renders nothing when no logo is configured, so a
     * default install is unchanged.
     */
    private function brandLogo(): string
    {
        if ($this->currentArea() === null) {
            return '';
        }

        try {
            $brand = app(BrandContext::class)->current();
            $url = $brand->logoUrl();
        } catch (Throwable) {
            return '';
        }

        if ($url === null) {
            return '';
        }

        return '<div class="px-4 pb-2" data-brand-logo="1">'
            .'<img src="'.e($url).'" alt="'.e($brand->name).'" class="max-h-10 w-auto">'
            .'</div>';
    }

    private function currentArea(): ?string
    {
        return match (Filament::getCurrentPanel()?->getId()) {
            'admin' => SkinSettings::AREA_BACKEND,
            'customer' => SkinSettings::AREA_CUSTOMER,
            default => null,
        };
    }

    /**
     * Renders the stored custom CSS/JS for the currently served panel area.
     * Content is admin-authored and injected verbatim by design (§3.25.8).
     */
    private function inject(string $prefix, string $tag): string
    {
        $area = $this->currentArea();

        if ($area === null) {
            return '';
        }

        try {
            /** @var string|null $content */
            $content = app(SkinSettings::class)->{$prefix.'_'.$area};
        } catch (Throwable) {
            // Settings not migrated yet (installer / first boot).
            return '';
        }

        if ($content === null || trim($content) === '') {
            return '';
        }

        return "<{$tag} data-skin-area=\"{$area}\">{$content}</{$tag}>";
    }
}
