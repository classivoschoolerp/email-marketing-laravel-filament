<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\Auth\RecordFailedLogin;
use App\Listeners\Auth\RecordSuccessfulLogin;
use App\Listeners\Auth\SendPasswordChangedNotification;
use App\Support\Roles\RoleChecker;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/**
 * WP-02 auth module wiring (spec §0.3, §1.2–1.4, §2.15.1–2): role enforcement
 * helper, login-log listeners, password strength defaults and referral capture.
 *
 * The customer panel's 2FA-aware login PAGE lives in CustomerPanelProvider —
 * see the note in register() for why it cannot be swapped in from here.
 */
class AuthModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per-request memoised role/permission lookups (spec §0.3).
        $this->app->scoped(RoleChecker::class);

        // NOTE: the customer panel's 2FA-aware login page is wired in
        // CustomerPanelProvider, NOT here. It used to be swapped in from a
        // `resolving(PanelRegistry::class)` callback, which runs AFTER
        // Panel::register() has already snapshotted the login route action to
        // register it as a Livewire component. The page still rendered (the
        // route action was updated) but its component name was never
        // registered, so submitting the form 419'd. Any panel route action
        // replaced after Panel::register() must either be set in the panel
        // provider or be followed by another $panel->register() — see
        // PluginPanelRegistrar, which does the latter.
    }

    public function boot(): void
    {
        // Delegated admin permissions (spec §0.3 F-0.3-011): dotted abilities
        // (e.g. "customers.impersonate") resolve against the three-tier role
        // tables, ignoring soft-disabled roles. Denial falls through so an
        // explicitly defined Gate ability of the same name still runs.
        Gate::before(function (mixed $user, string $ability, array $arguments = []): ?bool {
            if (! $user instanceof Model || ! str_contains($ability, '.')) {
                return null;
            }

            return app(RoleChecker::class)->hasActivePermission($user, $ability) ? true : null;
        });

        // Login log recording for both guards (spec §1.3 F-1.3-005).
        Event::listen(Login::class, RecordSuccessfulLogin::class);
        Event::listen(Failed::class, RecordFailedLogin::class);

        // Notify on password change (spec §1.4 F-1.4-003).
        Event::listen(PasswordReset::class, SendPasswordChangedNotification::class);

        // Password strength rules (spec §1.4).
        Password::defaults(fn (): Password => Password::min(8)->mixedCase()->numbers());

    }
}
