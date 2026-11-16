<?php

declare(strict_types=1);

use App\Http\Controllers\Automations\AutomationInboundHookController;
use App\Http\Controllers\Automations\AutomationReportExportController;
use App\Http\Controllers\Automations\AutomationTrackingController;
use App\Http\Middleware\SetCurrentCustomer;
use App\Services\Automations\AutomationHooks;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Automations module routes (WP-21, spec §2.4)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| Registration side-effect (D-41/D-51 pattern — providers file frozen
| mid-wave, revisit with route:cache at WP-24): AutomationHooks wires the
| model-event listeners that feed event-driven triggers (subscribe /
| unsubscribe / tag / field-change / campaign open + click).
*/

AutomationHooks::register();

// Public: automation email open pixel + click redirect (HMAC-signed,
// §2.4.3 email performance) and the per-automation inbound event hook
// (F-2.4.2-014).
Route::prefix('a')->name('automations.')->group(function (): void {
    Route::get('t/o/{uid}/{sig}', [AutomationTrackingController::class, 'open'])
        ->name('track.open');

    Route::get('t/c/{uid}/{sig}', [AutomationTrackingController::class, 'click'])
        ->name('track.click');

    Route::post('hook/{uid}/{token}', AutomationInboundHookController::class)
        ->middleware('throttle:60,1')
        ->name('hook');
});

// Customer-panel extras outside Livewire: report CSV export (F-2.4.3-007).
Route::middleware(['auth:customer', SetCurrentCustomer::class])
    ->prefix('customer/automations')
    ->name('automations.')
    ->group(function (): void {
        Route::get('{uid}/report/export', AutomationReportExportController::class)
            ->middleware('throttle:30,1')
            ->name('report.export');
    });
