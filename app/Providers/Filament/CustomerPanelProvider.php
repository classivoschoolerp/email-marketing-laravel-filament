<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Customer\Auth\Login as CustomerLogin;
use App\Filament\Customer\Pages\Dashboard;
use App\Http\Middleware\SetCurrentCustomer;
use App\Services\Search\PanelGlobalSearchProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class CustomerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('customer')
            ->path('customer')
            // The 2FA-aware login page (§1.3, WP-02) MUST be set here, not
            // swapped in later from AuthModuleServiceProvider. Panel::register()
            // snapshots getLoginRouteAction() to register it as a Livewire
            // component; a class swapped in after that renders fine but has no
            // registered component name, so the form's POST to /livewire/update
            // cannot resolve it and Livewire answers 419 "Page Expired".
            ->login(CustomerLogin::class)
            ->authGuard('customer')
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->discoverResources(in: app_path('Filament/Customer/Resources'), for: 'App\\Filament\\Customer\\Resources')
            ->discoverPages(in: app_path('Filament/Customer/Pages'), for: 'App\\Filament\\Customer\\Pages')
            ->discoverWidgets(in: app_path('Filament/Customer/Widgets'), for: 'App\\Filament\\Customer\\Widgets')
            // §4.4 F-4.4-001 (WP-40): global search over lists, campaigns,
            // subscribers and templates. A panel-level provider rather than
            // per-resource attributes so the tenant fence and the index-safe
            // predicates live in one reviewable file.
            ->globalSearch(PanelGlobalSearchProvider::class)
            ->pages([
                // WP-18: the §2.1 dashboard page (explicit widget line-up).
                Dashboard::class,
            ])
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            // F-4.3-001/002 (WP-39): the notification bell on the customer
            // panel, matching the admin panel (WP-24). Long-running jobs
            // (import, export, campaign send) write here at their
            // completion point and the poll turns that into a toast.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // isPersistent MATTERS (D-115). Filament applies auth middleware to
            // panel ROUTES only. Every form submission is a POST to
            // /livewire/update, which is not a panel route, so without this flag
            // SetCurrentCustomer never runs on the request that actually writes
            // the row — CurrentCustomer is empty and BelongsToCustomer stores
            // customer_id = NULL, orphaning the record from the tenant that
            // created it. Livewire only re-applies middleware listed here.
            ->authMiddleware([
                Authenticate::class,
                SetCurrentCustomer::class,
            ], isPersistent: true);
    }
}
