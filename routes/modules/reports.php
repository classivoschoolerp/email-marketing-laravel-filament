<?php

declare(strict_types=1);

use App\Http\Controllers\Reports\AdminLogExportDownloadController;
use App\Http\Middleware\AuthenticateAdmin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reports module routes (WP-17, spec §2.3.8 + §3.27)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| Customer campaign-report exports reuse the WP-08 signed download route
| (lists.exports.download) via ExportJob rows with scope=report — no extra
| route needed. This file only carries the admin log-export download
| (F-3.27-015): signed + admin-authenticated + permission-checked in the
| controller.
*/

Route::middleware([AuthenticateAdmin::class.':admin', 'signed'])
    ->get('admin-downloads/log-exports/{type}/{token}', AdminLogExportDownloadController::class)
    ->name('reports.admin-logs.download');
