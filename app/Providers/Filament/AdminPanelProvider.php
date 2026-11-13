<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use App\Filament\Admin\Pages\Dashboard;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
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

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            ->authGuard('admin')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
            // Fixes the sidebar order (Filament otherwise sorts groups
            // alphabetically) and collapses the groups an admin opens rarely,
            // so the panel lands on a short, scannable list by default instead
            // of ~100 flat items.
            ->navigationGroups([
                NavigationGroup::make(__('dashboards.admin.nav_group')),
                NavigationGroup::make(__('blacklist.admin_nav_group')),
                NavigationGroup::make(__('infra.navigation_group')),
                NavigationGroup::make(__('billing.navigation_group')),
                NavigationGroup::make(__('cms.nav_group')),
                NavigationGroup::make(__('admin.nav.access')),
                NavigationGroup::make(__('privacy.navigation_group'))->collapsed(),
                NavigationGroup::make(__('ops.navigation.location'))->collapsed(),
                NavigationGroup::make(__('themes.nav_group'))->collapsed(),
                NavigationGroup::make(__('plugins.nav_group'))->collapsed(),
                NavigationGroup::make(__('reports.admin.nav_group'))->collapsed(),
                NavigationGroup::make(__('settings.navigation_group'))->collapsed(),
            ])
            ->pages([
                // WP-18: the §3.1 dashboard page (explicit widget line-up).
                Dashboard::class,
            ])
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            // WP-24 (§6.6 F-6.6-002): the notification tray that surfaces new
            // application errors and resource-threshold alerts INSIDE the
            // admin area. Backed by the standard `notifications` table.
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
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
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
