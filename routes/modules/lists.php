<?php

declare(strict_types=1);

use App\Http\Controllers\Lists\ExportDownloadController;
use App\Http\Controllers\Lists\ImportErrorReportController;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Http\Middleware\SetCurrentCustomer;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Lists module routes (WP-08, spec §2.2.7/§2.2.8/§2.2.11)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| - The public embed-form POST target (§2.2.11), honeypot + captcha guarded
|   and rate-limited. Full public page rendering is WP-09.
| - The signed + policy-checked export download (§2.2.8) — a leaked signed
|   URL is useless without an authenticated session of the owning tenant.
| - The policy-checked import error report download (§2.2.7).
*/

// CSRF is excluded: the form is embedded on EXTERNAL sites (F-2.2.11-003)
// that cannot carry a session token — honeypot, captcha and the rate limit
// are the abuse controls here.
Route::post('/forms/{list_uid}/subscribe', PublicSubscribeController::class)
    ->middleware('throttle:15,1')
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('lists.forms.subscribe');

Route::middleware(['auth:customer', SetCurrentCustomer::class])
    ->prefix('customer')
    ->group(function (): void {
        Route::get('exports/{uid}/download', ExportDownloadController::class)
            ->middleware(['signed', 'throttle:30,1'])
            ->name('lists.exports.download');

        Route::get('imports/{uid}/errors', ImportErrorReportController::class)
            ->middleware('throttle:30,1')
            ->name('lists.imports.errors');
    });
