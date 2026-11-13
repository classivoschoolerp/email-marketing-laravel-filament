<?php

declare(strict_types=1);

use App\Http\Controllers\Install\InstallController;
use App\Http\Middleware\EnsureNotInstalled;
use App\Services\Install\Installer;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Installer wizard (WP-24, spec §6.9 F-6.9-001)
|--------------------------------------------------------------------------
| Public by necessity — there is no account until step 3. The whole group
| 404s once storage/app/installed exists (EnsureNotInstalled).
|
| The Installer is a SINGLETON so a test (or a deploy script) can point it at
| a scratch .env / marker path once and have every step honour it.
*/

app()->singleton(Installer::class);

Route::middleware([EnsureNotInstalled::class])
    ->prefix('install')
    ->name('install.')
    ->group(function (): void {
        Route::get('/', [InstallController::class, 'requirements'])->name('requirements');

        Route::get('database', [InstallController::class, 'database'])->name('database');
        Route::post('database', [InstallController::class, 'storeDatabase'])->name('database.store');

        Route::get('admin', [InstallController::class, 'admin'])->name('admin');
        Route::post('admin', [InstallController::class, 'storeAdmin'])->name('admin.store');

        Route::get('license', [InstallController::class, 'license'])->name('license');
        Route::post('license', [InstallController::class, 'storeLicense'])->name('license.store');

        Route::get('services', [InstallController::class, 'services'])->name('services');
        Route::post('complete', [InstallController::class, 'complete'])->name('complete');
    });
