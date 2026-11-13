<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AdminIpAllowlist;
use App\Http\Middleware\EnforceAccountIpAllowlist;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnforcePanelDomain;
use App\Http\Middleware\EnforcePasswordExpiry;
use App\Http\Middleware\ForceHttps;
use App\Listeners\Security\RecordPasswordChange;
use App\Models\Customer;
use App\Models\SubscriberFieldValue;
use App\Models\User;
use App\Observers\ConsentEventObserver;
use App\Rules\PasswordPolicy;
use Filament\PanelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/**
 * WP-22 compliance / security / privacy wiring (spec §4.5, §3.25.15–16).
 *
 * Registered LAST in bootstrap/providers.php so its `Password::defaults()`
 * call wins over the WP-02 AuthModuleServiceProvider default — that single
 * override makes every existing `Password::defaults()` caller (the admin
 * Security page, CustomerResource, UserResource) obey the §3.25.15 password
 * policy without those files being touched.
 *
 * Everything else here follows the established "mutate from a provider,
 * never edit the WP-01 panel providers" pattern (AuthModuleServiceProvider,
 * SkinServiceProvider, I18nServiceProvider):
 *
 *  - {@see AdminIpAllowlist} on the admin panel's pre-auth middleware stack
 *    (F-3.25.15-005);
 *  - {@see EnforceIdleTimeout} on both panels' auth stacks (F-3.25.15-002);
 *  - {@see EnforceAccountIpAllowlist} on the customer panel's auth stack
 *    (§2.15.2 F-2.15.2-004, WP-46);
 *  - {@see ForceHttps} on the `web` group — public frontend, auth module,
 *    Livewire and both panels (F-3.25.15-004);
 *  - {@see RecordPasswordChange} on both authenticatables' `saved` events, so
 *    every password write path feeds password_histories (F-3.25.15-003);
 *  - {@see ConsentEventObserver} on subscriber field values (F-4.5-008).
 */
class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->resolving(PanelRegistry::class, function (PanelRegistry $registry): void {
            $panels = $registry->all();

            // F-3.25.15-005: the allowlist must bite before authentication,
            // so it goes on the panel middleware stack, not authMiddleware.
            ($panels['admin'] ?? null)?->middleware([AdminIpAllowlist::class]);

            // F-3.25.15-002 idle timeout + F-3.25.15-003 password expiry:
            // both only matter once signed in.
            foreach (['admin', 'customer'] as $panelId) {
                ($panels[$panelId] ?? null)?->authMiddleware([
                    EnforceIdleTimeout::class,
                    EnforcePasswordExpiry::class,
                ]);
            }

            // §2.15.2 F-2.15.2-004 (WP-46): the customer's OWN account IP
            // allowlist. Customer panel only — a tenant's rule must never be
            // able to lock the operator out of the admin panel, including
            // while impersonating (F-0.2-006).
            ($panels['customer'] ?? null)?->authMiddleware([EnforceAccountIpAllowlist::class]);

            // §4.7 F-4.7-002 (WP-46): which accounts a brand host's customer
            // panel admits. Pre-auth, because the "this host serves no panel"
            // half is about the HOST, not the visitor.
            ($panels['customer'] ?? null)?->middleware([EnforcePanelDomain::class]);
        });
    }

    public function boot(): void
    {
        // F-3.25.15-004 — force HTTPS + HSTS for everything on the web group.
        $this->app['router']->pushMiddlewareToGroup('web', ForceHttps::class);

        // F-3.25.15-003 — settings-driven password strength everywhere.
        Password::defaults(fn (): Password => PasswordPolicy::strength());

        // F-3.25.15-003 — password history + password_changed_at on every
        // write path (profile, admin security, resets, admin resources).
        foreach ([User::class, Customer::class] as $authenticatable) {
            Event::listen(
                'eloquent.saved: '.$authenticatable,
                static fn (Model $model): mixed => app(RecordPasswordChange::class)->handle($model),
            );
        }

        // F-4.5-008 — consent change audit trail.
        SubscriberFieldValue::observe(ConsentEventObserver::class);
    }
}
