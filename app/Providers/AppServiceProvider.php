<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Subscriber;
use App\Observers\SubscriberSearchObserver;
use App\Support\Roles\SuperAdminGate;
use App\Support\Search\SearchIndexRegistry;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request/job lifecycle (spec §0.2).
        $this->app->scoped(CurrentCustomer::class);

        // §0.1 F-0.1-008 — search-engine driver registry (docs/ARCHITECTURE.md rule 2).
        $this->app->singleton(SearchIndexRegistry::class);
    }

    public function boot(): void
    {
        // Super Admin is granted everything (spec §0.3), except mutating locked
        // system roles where SuperAdminGate falls through to RolePolicy (WP-02).
        Gate::before(
            fn (mixed $user, string $ability, array $arguments = []): ?bool => app(SuperAdminGate::class)
                ->handle($user, $ability, $arguments)
        );

        // §0.1 F-0.1-008 — index freshness. Registered unconditionally and
        // gated INSIDE the observer via the DatabaseSearchIndex null object,
        // not by a config read here: config is mutable at runtime (and in
        // tests), so a boot-time `if` would silently pin the decision to
        // whatever .env said at boot. On the default driver every observer
        // method returns before it touches anything.
        Subscriber::observe(SubscriberSearchObserver::class);
    }
}
