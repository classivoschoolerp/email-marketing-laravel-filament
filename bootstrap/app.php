<?php

use App\Http\Middleware\ApiOffline;
use App\Http\Middleware\SiteOffline;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // WP-04 (spec §3.25.1): site/API offline switches. SiteOffline runs globally;
        // ApiOffline guards the `api` middleware group (API routes arrive with WP-19).
        $middleware->append(SiteOffline::class);
        $middleware->appendToGroup('api', ApiOffline::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
