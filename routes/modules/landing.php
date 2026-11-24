<?php

declare(strict_types=1);

use App\Http\Controllers\Landing\BuilderController;
use App\Http\Controllers\Public\LandingPublicController;
use App\Http\Middleware\SetCurrentCustomer;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Landing pages module routes (WP-34, spec §2.6 + §1.8)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| PUBLIC half (§1.8): no auth, no tenancy. The slug is the key and only a LIVE
| page answers; a page also only answers on a host that may serve it (the app
| host or its own assigned custom domain), which is what keeps globally
| unique slugs from letting one tenant's page render on another's domain —
| see LandingPageUrl::servableOnHost().
|
| The routes are host-agnostic by design (Laravel matches any Host unless
| ->domain() is used), so a verified custom domain CNAME'd at the app serves
| /p/{slug} with no extra route and no wildcard route that could swallow the
| rest of the application.
|
| CSRF is excluded on `submit`: a page served on the customer's own domain
| cannot carry the app's session cookie. The honeypot, the configured captcha
| and the route rate limit are the abuse controls — the same trade-off, for
| the same reason, as routes/modules/forms.php's submit endpoint.
|
| PANEL half (§2.6): the GrapesJS autosave + asset upload endpoints, behind
| the customer guard; row authorization is LandingPagePolicy::update inside
| the controller.
*/

Route::prefix('p/{slug}')->group(function (): void {
    Route::get('/', [LandingPublicController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('landing.public.show');

    Route::get('thank-you', [LandingPublicController::class, 'thanks'])
        ->middleware('throttle:60,1')
        ->name('landing.public.thanks');

    Route::post('submit', [LandingPublicController::class, 'submit'])
        ->middleware('throttle:15,1')
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->name('landing.public.submit');

    // §1.8 F-1.8-006 password gate. CSRF is KEPT here (unlike `submit`): the
    // gate is session-backed by definition, so a request that cannot carry a
    // session cannot be unlocked anyway. The tight throttle is the
    // brute-force control.
    Route::post('unlock', [LandingPublicController::class, 'unlock'])
        ->middleware('throttle:10,1')
        ->name('landing.public.unlock');
});

Route::middleware(['auth:customer', SetCurrentCustomer::class])
    ->prefix('customer/landing-pages')
    ->name('landing.')
    ->group(function (): void {
        Route::post('{uid}/builder/save', [BuilderController::class, 'save'])
            ->middleware('throttle:120,1')
            ->name('builder.save');

        Route::post('{uid}/builder/assets', [BuilderController::class, 'uploadAsset'])
            ->middleware('throttle:60,1')
            ->name('builder.assets');
    });
