<?php

declare(strict_types=1);

use App\Contracts\CampaignWebViewSource;
use App\Http\Controllers\Campaigns\BuilderController;
use App\Http\Controllers\Campaigns\ReportPrintController;
use App\Http\Controllers\Campaigns\SpintaxController;
use App\Http\Controllers\Public\CampaignForwardController;
use App\Http\Middleware\SetCurrentCustomer;
use App\Services\Campaigns\DatabaseCampaignWebViewSource;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Campaigns module routes (WP-11/12, spec §2.3)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| Container binding (D-41 pattern — the providers list stays frozen while
| agents run in parallel): the real CampaignWebViewSource replaces the
| WP-09 Null default so /campaigns/{uid}/web-view, /share and the secret
| report URL go live. DatabaseCampaignWebViewSource extends the Null source
| on purpose: WP-09's plumbing test asserts the resolved default is a Null
| instance and that unknown uids 404 — both remain true.
*/

app()->bind(CampaignWebViewSource::class, DatabaseCampaignWebViewSource::class);

Route::middleware(['auth:customer', SetCurrentCustomer::class])
    ->prefix('customer/campaigns')
    ->name('campaigns.')
    ->group(function (): void {
        // GrapesJS campaign-content builder endpoints (reuses the WP-10
        // template-builder JS with campaign save/asset URLs).
        Route::post('{uid}/builder/save', [BuilderController::class, 'save'])
            ->middleware('throttle:120,1')
            ->name('builder.save');

        Route::post('{uid}/builder/assets', [BuilderController::class, 'uploadAsset'])
            ->middleware('throttle:60,1')
            ->name('builder.assets');

        // Spintax preview/expander (spec §2.3.3 F-2.3.3-022).
        Route::post('spintax/preview', [SpintaxController::class, 'preview'])
            ->middleware('throttle:60,1')
            ->name('spintax.preview');

        // WP-35 §2.3.8 F-2.3.8-020 — print / PDF report. Authenticated and
        // tenant-scoped; the anonymous read-only view is the F-2.3.4-020
        // secret share URL.
        Route::get('{campaign}/report/print', ReportPrintController::class)
            ->middleware('throttle:60,1')
            ->name('report.print');
    });

/*
| WP-35 — §1.6 F-1.6-002 forward-to-a-friend, served at the §1.7 F-1.7-004
| tracker URL. Public and unauthenticated exactly like the other §1.7
| endpoints (uid = access secret); the POST carries the honeypot + configured
| captcha and a tighter throttle, matching the §1.5 public form convention.
*/
Route::prefix('campaigns/{campaign_uid}/track-forward/{subscriber_uid}')->group(function (): void {
    Route::get('/', [CampaignForwardController::class, 'form'])
        ->middleware('throttle:60,1')
        ->name('campaigns.track.forward');

    Route::post('/', [CampaignForwardController::class, 'submit'])
        ->middleware('throttle:10,1')
        ->name('campaigns.track.forward.submit');
});
