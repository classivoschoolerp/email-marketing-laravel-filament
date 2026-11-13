<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Domains\SslCertificateInspector;
use App\Http\Middleware\EnforceDomainAuth;
use App\Http\Middleware\ResolveBrand;
use App\Models\Customer;
use App\Observers\CustomerBrandObserver;
use App\Services\Branding\BrandContext;
use App\Services\Branding\BrandedSystemEmailRenderer;
use App\Services\Domains\StreamSslCertificateInspector;
use App\Services\SystemEmails\SystemEmailRenderer;
use App\Services\Themes\ThemeRegistry;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * WP-38 wiring (spec §6.3 / §6.4 / §6.7 / §1.1 / §1.11).
 *
 * The ONE provider this work package adds to bootstrap/providers.php. It does
 * four things and nothing else:
 *
 *  1. binds the theme registry and the brand context as scoped singletons, so
 *     one request reads the `themes` / `domains` tables at most once;
 *  2. pushes {@see ResolveBrand} onto the `web` middleware group from here
 *     rather than editing bootstrap/app.php — the framework kernel file stays
 *     untouched and the brand layer can be removed by deleting one provider;
 *  3. shares the resolved brand with every view, so a Blade template never
 *     has to resolve branding itself and public pages cannot disagree with
 *     the panels about whose logo they are showing;
 *  4. registers the §6.7 social-auth driver registry (the §2.9.2/§6.1 driver
 *     registry pattern: a new identity provider is an adapter, not a core
 *     change) and the TLS inspector behind an interface so tests can observe
 *     certificate reporting without opening a socket.
 *
 * MUST be registered before PluginModuleServiceProvider, which stays last.
 */
class BrandingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ThemeRegistry::class);
        $this->app->scoped(BrandContext::class);

        // Filament panel routes do NOT run the `web` middleware group — each
        // panel carries its own stack — so the brand middleware is attached to
        // the registered panels here, the same way SkinServiceProvider attaches
        // Enforce2fa. Without this a branded host would render the panels with
        // the operator's own logo, and §6.4 F-6.4-003's per-domain login switch
        // would not reach the customer login page at all.
        $this->app->resolving(PanelRegistry::class, function (PanelRegistry $registry): void {
            foreach (['admin', 'customer'] as $panelId) {
                ($registry->all()[$panelId] ?? null)?->middleware([
                    ResolveBrand::class,
                    EnforceDomainAuth::class,
                ]);
            }
        });

        $this->app->singleton(SslCertificateInspector::class, StreamSslCertificateInspector::class);

        // §6.4 F-6.4-002 per-domain email templates. Bound over WP-20's
        // renderer so all 18 consumers pick the brand layer up without any of
        // them being edited; falls back to the parent behaviour verbatim when
        // the request is not on a brand host.
        $this->app->bind(
            SystemEmailRenderer::class,
            fn ($app): SystemEmailRenderer => new BrandedSystemEmailRenderer($app->make(BrandContext::class)),
        );

    }

    public function boot(): void
    {
        // Appended to the group, so it runs after StartSession/auth wiring and
        // before any route middleware. ResolveBrand itself shares the brand
        // with the view layer — one call per request rather than a wildcard
        // view composer that would fire on every Filament sub-component.
        // EnforceDomainAuth must follow it: it reads what ResolveBrand parked.
        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('web', ResolveBrand::class);
        $router->pushMiddlewareToGroup('web', EnforceDomainAuth::class);

        // §6.4 F-6.4-003: stamp the signing-up brand onto new customers, on
        // every creation path rather than only the §1.2 form.
        Customer::observe(CustomerBrandObserver::class);

        // §3.21 F-3.21-003: the configured login background image, applied to
        // Filament's simple (auth) layout only.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => $this->loginBackgroundStyles(),
        );
    }

    /**
     * A <style> block that paints the auth screen with the brand's login
     * background. Only a URL this application generated is interpolated —
     * the stored value is a path on the public disk (or an absolute URL an
     * operator typed), and it is escaped before it reaches the CSS.
     */
    private function loginBackgroundStyles(): string
    {
        try {
            $url = $this->app->make(BrandContext::class)->current()->loginBackgroundUrl();
        } catch (\Throwable) {
            return '';
        }

        if ($url === null || str_contains($url, '"') || str_contains($url, ')')) {
            return '';
        }

        return '<style data-login-background="1">'
            .'.fi-simple-layout{background-image:url("'.e($url).'");background-size:cover;background-position:center;}'
            .'</style>';
    }
}
