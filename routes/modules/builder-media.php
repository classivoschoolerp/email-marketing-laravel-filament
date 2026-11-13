<?php

declare(strict_types=1);

use App\Http\Controllers\Templates\BuilderMediaController;
use App\Http\Middleware\SetCurrentCustomer;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shared builder media (WP-42, spec §2.5.4 F-2.5.4-009 / F-2.5.4-010)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| Stock image search/import and the image-editor save endpoint. Both back
| resources/js/template-builder.js, which the template builder and the
| campaign content builder both mount, and both produce TENANT-owned assets
| with no document foreign key — so they get their own module file, exactly
| like routes/modules/blocks.php and routes/modules/brand-kit.php.
|
| No uid appears in any route: the owner is the authenticated customer.
*/

Route::middleware(['auth:customer', SetCurrentCustomer::class])
    ->prefix('customer/builder')
    ->name('builder-media.')
    ->group(function (): void {
        // F-2.5.4-010 stock image library.
        Route::get('stock/search', [BuilderMediaController::class, 'searchStock'])
            ->middleware('throttle:60,1')
            ->name('stock.search');

        Route::post('stock/import', [BuilderMediaController::class, 'importStock'])
            ->middleware('throttle:30,1')
            ->name('stock.import');

        // F-2.5.4-009 image editor: store the edited canvas as a new asset.
        Route::post('images', [BuilderMediaController::class, 'storeEditedImage'])
            ->middleware('throttle:60,1')
            ->name('images.store');
    });
