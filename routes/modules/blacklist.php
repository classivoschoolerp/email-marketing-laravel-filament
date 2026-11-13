<?php

declare(strict_types=1);

use App\Http\Controllers\Blacklist\AdminBlacklistExportDownloadController;
use App\Http\Controllers\Blacklist\BlacklistExportDownloadController;
use App\Http\Controllers\Suppression\SuppressionExportDownloadController;
use App\Http\Controllers\Verification\VerificationResultDownloadController;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\SetCurrentCustomer;
use App\Services\Blacklist\BlacklistImportGuard;
use App\Services\Imports\ImportGuard;
use App\Services\Verification\ProviderRegistry;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Blacklist / suppression / verification module (WP-16, §2.9.10–12, §3.17–18)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
| Boot-time bindings follow the D-41/D-51 precedent (providers file frozen
| mid-wave; move into a provider before enabling route:cache — WP-24):
|  - ImportGuard: the WP-08 Null seam is replaced by the real blacklist
|    guard (apply-at-import enforcement, F-2.2.7-010 / §3.17).
|  - ProviderRegistry singleton so plugin register() calls persist (D-12).
*/

if (! app()->bound(ImportGuard::class)) {
    app()->bind(ImportGuard::class, BlacklistImportGuard::class);
    app()->singleton(ProviderRegistry::class);
}

Route::middleware(['auth:customer', SetCurrentCustomer::class])
    ->prefix('customer')
    ->group(function (): void {
        // Queued customer blacklist export (§2.9.10 F-2.9.10-003).
        Route::get('blacklist/export-download', BlacklistExportDownloadController::class)
            ->middleware('throttle:30,1')
            ->name('blacklist.export.download');

        // Queued suppression-list export (§2.9.11 F-2.9.11-005).
        Route::get('suppression/{uid}/export-download', SuppressionExportDownloadController::class)
            ->middleware('throttle:30,1')
            ->name('suppression.export.download');

        // Verification result CSV (§2.9.12 F-2.9.12-008).
        Route::get('verifications/{uid}/results-download', VerificationResultDownloadController::class)
            ->middleware('throttle:30,1')
            ->name('verification.results.download');
    });

// Admin-wide queued blacklist export (§3.17 F-3.17-002).
Route::middleware([AuthenticateAdmin::class.':admin'])
    ->prefix('admin')
    ->group(function (): void {
        Route::get('blacklist/export-download', AdminBlacklistExportDownloadController::class)
            ->middleware('throttle:30,1')
            ->name('admin.blacklist.export.download');
    });
