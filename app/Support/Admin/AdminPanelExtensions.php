<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Filament\Admin\Pages\Security;
use App\Models\User;
use Filament\Events\ServingFilament;
use Filament\Facades\Filament;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Event;

/**
 * WP-03 panel wiring that cannot live in a service provider: agents may not
 * touch bootstrap/providers.php, so routes/modules/admin.php (auto-loaded by
 * ModulesServiceProvider on every boot) calls register() instead — the same
 * boot-time window a provider's boot() would use. If route caching is ever
 * enabled in production, move this call into a registered provider first
 * (cached route files are not executed).
 *
 * - Force-password-reset (spec §3.2 F-3.2-004): admins flagged by another
 *   admin are redirected to the admin Security page until they change their
 *   password. Registered via the ServingFilament event, which Filament's
 *   DispatchServingFilamentEvent middleware fires on panel requests.
 */
final class AdminPanelExtensions
{
    /**
     * Called once per application boot (route module load). No static
     * memo — each app instance (tests boot many) owns its dispatcher and
     * render-hook registry.
     */
    public static function register(): void
    {
        Event::listen(ServingFilament::class, function (): void {
            self::enforceForcedPasswordReset();
        });
    }

    private static function enforceForcedPasswordReset(): void
    {
        if (Filament::getCurrentPanel()?->getId() !== 'admin') {
            return;
        }

        $user = auth('admin')->user();

        if (! $user instanceof User || ! $user->force_password_reset) {
            return;
        }

        $request = request();

        // The Security page (password change), logout and Livewire component
        // round-trips (the Security page's own form posts) stay reachable.
        if ($request->routeIs(
            'filament.admin.pages.security',
            'filament.admin.auth.logout',
            'livewire.*',
        )) {
            return;
        }

        throw new HttpResponseException(
            redirect()->to(Security::getUrl(panel: 'admin'))
        );
    }
}
