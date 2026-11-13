<?php

declare(strict_types=1);
use App\Support\Roles\RoleChecker;
use App\Support\Tenancy\CurrentCustomer;

/*
|--------------------------------------------------------------------------
| Laravel Octane (spec §0.1 F-0.1-006 — OPTIONAL, high-throughput sending)
|--------------------------------------------------------------------------
| Octane (FrankenPHP or Swoole) is not installed by default: it keeps the
| application in memory between requests, which changes the lifecycle rules
| every contributor has to follow. This file ships pre-written so a Linux
| deployment can enable it with:
|
|     composer require laravel/octane
|     php artisan octane:install --server=frankenphp
|     php artisan octane:start --workers=auto --max-requests=500
|
| `--workers=auto` matches App\Support\System\Cpu::cores(); the Ops page
| shows the detected count and the suggested layout.
|
| STATE-SAFETY AUDIT (see docs/tech-stack.md for the full note). Octane keeps
| singletons alive across requests, so anything holding request state must be
| flushed. In this codebase:
|
|  - CurrentCustomer is registered with `$this->app->scoped()` in
|    AppServiceProvider, which Octane resets per request — correct as written;
|  - RoleChecker memoises per request and is also `scoped`;
|  - the settings repository (spatie) is request-scoped by the package;
|  - App\Support\Ops\SlowQueryRecorder uses static flags: `$registered` must
|    NOT be reset per request (the DB::listen hook survives), while
|    `$recording` is only ever true inside one call — both are safe;
|  - App\Support\System\Cpu memoises an immutable host fact — safe;
|  - the WP-24 GeoProviderRegistry is a singleton holding provider instances
|    that read settings lazily on each call — safe;
|  - the `flush` list below additionally clears the tenant context and role
|    memo between requests as belt-and-braces.
|
| Anything added later that caches a Customer, a User, a Request or a
| per-request setting on a singleton MUST be added to `flush` below.
*/

return [

    'server' => env('OCTANE_SERVER', 'frankenphp'),

    'https' => env('OCTANE_HTTPS', false),

    'listeners' => [
        // Left at the package defaults; Octane merges its own list.
    ],

    /*
     * Containers/instances rebound before each request. These are the
     * request-scoped services of this application (see the audit above).
     */
    'flush' => [
        CurrentCustomer::class,
        RoleChecker::class,
    ],

    /*
     * Services resolved once at worker boot. Spelled out literally instead of
     * calling Laravel\Octane\Octane::defaultServicesToWarm(): config files are
     * loaded on EVERY boot, including on hosts where the package is absent,
     * where that call would be a fatal error.
     */
    'warm' => [
        'auth',
        'cache',
        'cache.store',
        'config',
        'cookie',
        'db',
        'db.factory',
        'db.transactions',
        'encrypter',
        'files',
        'hash',
        'log',
        'router',
        'routes',
        'session',
        'session.store',
        'translator',
        'url',
        'view',
    ],

    'cache' => [
        'rows' => 1000,
        'bytes' => 10000,
    ],

    'tables' => [
        'example:1000' => [
            'name' => 'string:1000',
            'votes' => 'int',
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config',
        'database',
        'lang',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        '.env',
    ],

    'garbage' => 50,

    'max_execution_time' => 30,

];
