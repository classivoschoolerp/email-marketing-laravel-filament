<?php

declare(strict_types=1);

use App\Http\Controllers\Public\FormPublicController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Forms & popups module routes (WP-31, spec §2.7)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| Everything here is public and resolved by the form uid — no auth, no
| tenancy: a uid that belongs to another customer is simply not found, and
| a form that is not Active answers 404 whichever endpoint is hit.
|
| CSRF is excluded on the three machine endpoints (impression beacon, per-step
| validation, submit) because the embed runs on EXTERNAL origins that cannot carry a session
| token — the honeypot, the configured captcha and the route rate limits are
| the abuse controls, exactly as for the §2.2.11 embed endpoint.
|
| Note the path shapes never collide with routes/modules/lists.php's
| `POST /forms/{list_uid}/subscribe`: every route below carries a distinct
| trailing segment.
*/

Route::prefix('forms/{uid}')->group(function (): void {
    Route::get('embed.js', [FormPublicController::class, 'script'])
        ->middleware('throttle:120,1')
        ->name('forms.public.script');

    Route::get('view', [FormPublicController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('forms.public.show');

    Route::post('impression', [FormPublicController::class, 'impression'])
        ->middleware('throttle:120,1')
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->name('forms.public.impression');

    Route::post('submit', [FormPublicController::class, 'submit'])
        ->middleware('throttle:15,1')
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->name('forms.public.submit');

    // Per-step validation for a multi-step form (F-2.7-004). Read-only: it
    // creates nothing, so its rate limit is looser than submit's — a visitor
    // legitimately crosses several steps in one minute.
    Route::post('step', [FormPublicController::class, 'step'])
        ->middleware('throttle:60,1')
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->name('forms.public.step');

    // Lead magnet (F-2.7-008): the URL is signed at submit time and expires,
    // so the asset never becomes a shareable public link.
    Route::get('download/{submission}', [FormPublicController::class, 'download'])
        ->middleware(['signed', 'throttle:30,1'])
        ->name('forms.public.download');

    // CORS preflight, for hosts whose CSP turns the simple POST into one.
    Route::options('{any}', [FormPublicController::class, 'preflight'])
        ->where('any', 'impression|submit|step')
        ->withoutMiddleware([ValidateCsrfToken::class]);
});
