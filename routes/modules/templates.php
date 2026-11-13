<?php

declare(strict_types=1);

use App\Http\Controllers\Templates\BuilderController;
use App\Http\Middleware\SetCurrentCustomer;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Templates module routes (WP-10, spec §2.5.4)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
| Builder autosave + asset upload for the GrapesJS editor page. The template
| is addressed by uid; authorization is EmailTemplatePolicy::update.
*/

Route::middleware(['auth:customer', SetCurrentCustomer::class])
    ->prefix('customer/templates')
    ->name('templates.')
    ->group(function (): void {
        Route::post('{uid}/builder/save', [BuilderController::class, 'save'])
            ->middleware('throttle:120,1')
            ->name('builder.save');

        Route::post('{uid}/builder/assets', [BuilderController::class, 'uploadAsset'])
            ->middleware('throttle:60,1')
            ->name('builder.assets');
    });
