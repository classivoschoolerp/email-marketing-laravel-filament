<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ListsOverviewExportDownloadController;
use App\Http\Middleware\AuthenticateAdmin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Overviews module routes (WP-18, spec §2.1 / §3.1 / §3.6 / §3.7 / §3.11)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| - Lists-overview export download (F-3.6-005): signed + admin-authenticated
|   + `lists.view` re-checked in the controller (WP-17 log-export pattern).
*/

Route::middleware([AuthenticateAdmin::class.':admin', 'signed'])
    ->get('admin-downloads/lists-overview/{token}', ListsOverviewExportDownloadController::class)
    ->name('overviews.lists-export.download');
