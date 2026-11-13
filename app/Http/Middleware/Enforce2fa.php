<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Admin\Pages\Security as AdminSecurity;
use App\Filament\Customer\Pages\Security;
use App\Models\Customer;
use App\Models\User;
use App\Settings\TwoFactorSettings;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Enforces the §3.25.6 force-2FA toggles (F-3.25.6-002). Attached to both
 * panels' auth middleware by {@see \App\Providers\SkinServiceProvider}.
 *
 * - Customer panel: an authenticated customer without 2FA enabled is
 *   redirected to the Security page (WP-02) with a notice. The Security page
 *   itself and logout stay reachable so enrolment is possible.
 * - Admin panel (WP-03, closes D-35): identical treatment — admins without
 *   2FA are redirected to the admin Security page, which enrols them via the
 *   shared TwoFactorService.
 */
class Enforce2fa
{

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $settings = app(TwoFactorSettings::class);
            $enabled = $settings->enabled;
            $forceAdmins = $settings->force_admins;
            $forceCustomers = $settings->force_customers;
        } catch (Throwable) {
            // Settings not migrated yet (installer / first boot) — never lock out.
            return $next($request);
        }

        if (! $enabled) {
            return $next($request);
        }

        return match (Filament::getCurrentPanel()?->getId()) {
            'customer' => $forceCustomers ? $this->handleCustomer($request, $next) : $next($request),
            'admin' => $forceAdmins ? $this->handleAdmin($request, $next) : $next($request),
            default => $next($request),
        };
    }

    private function handleCustomer(Request $request, Closure $next): Response
    {
        $customer = auth('customer')->user();

        if (! $customer instanceof Customer || $customer->twofa_enabled) {
            return $next($request);
        }

        // The Security page (enrolment) and logout must stay reachable.
        if ($request->routeIs(
            'filament.customer.pages.security',
            'filament.customer.auth.logout',
        )) {
            return $next($request);
        }

        Notification::make()
            ->warning()
            ->title(__('settings.twofa.notices.customer_required_title'))
            ->body(__('settings.twofa.notices.customer_required_body'))
            ->send();

        return redirect()->to(Security::getUrl(panel: 'customer'));
    }

    private function handleAdmin(Request $request, Closure $next): Response
    {
        $user = auth('admin')->user();

        if (! $user instanceof User || $user->twofa_enabled) {
            return $next($request);
        }

        // The Security page (enrolment) and logout must stay reachable.
        if ($request->routeIs(
            'filament.admin.pages.security',
            'filament.admin.auth.logout',
        )) {
            return $next($request);
        }

        Notification::make()
            ->warning()
            ->title(__('settings.twofa.notices.admin_required_title'))
            ->body(__('settings.twofa.notices.admin_required_body'))
            ->send();

        return redirect()->to(AdminSecurity::getUrl(panel: 'admin'));
    }
}
