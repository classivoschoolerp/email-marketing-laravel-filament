<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ModuleOverviewExportDownloadController;
use App\Http\Controllers\Pwa\PwaAssetController;
use App\Http\Middleware\AuthenticateAdmin;
use App\Support\Pwa\PwaPanelExtensions;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Analytics & mobile module (WP-40, spec §4.2 / §4.4 / §4.8)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| §4.8 F-4.8-002: the PWA is served as two static files from public/
| (manifest.webmanifest + sw.js). Only the <head> wiring needs a boot hook.
|
| D-41 / D-51 caveat: registering bindings and schedules from a route file
| makes `php artisan route:cache` unsafe — cached routes skip the route FILES.
| docs/install-vps.md documents this and the deploy scripts do not run it.
*/

PwaPanelExtensions::register();

/*
| PWA documents (F-4.8-002). The offline shell is deliberately a route rather
| than a static file so it renders in the operator's configured locale and
| brand — and it is the ONLY document the service worker pre-caches, because
| it is the only one that contains no account data.
*/
Route::get('manifest.webmanifest', [PwaAssetController::class, 'manifest'])->name('pwa.manifest');
Route::get('sw.js', [PwaAssetController::class, 'serviceWorker'])->name('pwa.service-worker');
Route::view('offline', 'pwa.offline')->name('pwa.offline');

/*
| §3.9 / §3.10 / §3.12 module-overview export downloads. Signed AND
| admin-authenticated AND permission-re-checked in the controller — the
| WP-17/WP-18 admin-download pattern.
*/
Route::middleware([AuthenticateAdmin::class.':admin', 'signed'])
    ->get('admin-downloads/module-overview/{type}/{token}', ModuleOverviewExportDownloadController::class)
    ->name('overviews.module-export.download');
