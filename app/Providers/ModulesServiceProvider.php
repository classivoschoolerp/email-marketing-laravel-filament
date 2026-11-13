<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-loads per-module route files so work packages never contend for shared route
 * files: drop a file in routes/modules/ and it is registered with the web middleware
 * group. API modules use routes/modules/api/*.php under the api group + /api prefix.
 */
class ModulesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (glob(base_path('routes/modules/*.php')) ?: [] as $file) {
            Route::middleware('web')->group($file);
        }

        foreach (glob(base_path('routes/modules/api/*.php')) ?: [] as $file) {
            Route::middleware('api')->prefix('api')->group($file);
        }
    }
}
