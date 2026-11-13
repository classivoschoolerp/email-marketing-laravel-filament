<?php

declare(strict_types=1);

use App\Http\Controllers\Public\CampaignPagesController;
use App\Http\Controllers\Public\EmailBlockRequestController;
use App\Http\Controllers\Public\ListPagesController;
use App\Http\Controllers\Public\TrackingController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public frontend routes (WP-09, spec §1.5 / §1.6 / §1.7 / §1.10)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
| No auth anywhere: tenancy is derived from the list/subscriber uid inside
| the controllers (uid = access secret, MailWizz pattern). Every endpoint
| is rate-limited; the two machine endpoints (one-click unsubscribe per
| RFC 8058, and nothing else) are CSRF-exempt.
*/

// §1.5 — list public pages.
Route::prefix('lists/{list_uid}')->middleware('throttle:60,1')->group(function (): void {
    Route::get('subscribe', [ListPagesController::class, 'subscribeForm'])
        ->name('lists.public.subscribe');

    Route::get('subscribe/pending/{subscriber_uid}', [ListPagesController::class, 'pending'])
        ->name('lists.public.pending');

    Route::get('subscribe/confirmed/{subscriber_uid}', [ListPagesController::class, 'confirmed'])
        ->name('lists.public.subscribed');

    // Signed double opt-in link (F-1.5-004) — tampering → 403.
    Route::get('confirm-subscription/{subscriber_uid}', [ListPagesController::class, 'confirmSubscription'])
        ->middleware('signed')
        ->name('lists.public.confirm');

    Route::get('{subscriber_uid}/update-profile', [ListPagesController::class, 'updateProfileForm'])
        ->name('lists.public.update_profile');

    Route::post('{subscriber_uid}/update-profile', [ListPagesController::class, 'updateProfileSave'])
        ->middleware('throttle:15,1')
        ->name('lists.public.update_profile.save');

    Route::get('{subscriber_uid}/unsubscribe', [ListPagesController::class, 'unsubscribeForm'])
        ->name('lists.public.unsubscribe');

    Route::post('{subscriber_uid}/unsubscribe', [ListPagesController::class, 'unsubscribeConfirm'])
        ->middleware('throttle:15,1')
        ->name('lists.public.unsubscribe.confirm');

    Route::post('{subscriber_uid}/unsubscribe/confirm', [ListPagesController::class, 'unsubscribeProcess'])
        ->middleware('throttle:15,1')
        ->name('lists.public.unsubscribe.process');

    Route::get('{subscriber_uid}/unsubscribe/success', [ListPagesController::class, 'unsubscribeSuccess'])
        ->name('lists.public.unsubscribe.success');

    // F-1.5-012 — preference center: pick topics/frequency instead of a
    // full unsubscribe. Same uid-as-secret access model as update-profile.
    Route::get('{subscriber_uid}/preferences', [ListPagesController::class, 'preferencesForm'])
        ->name('lists.public.preferences');

    Route::post('{subscriber_uid}/preferences', [ListPagesController::class, 'preferencesSave'])
        ->middleware('throttle:15,1')
        ->name('lists.public.preferences.save');

    // F-1.5-013 — resubscribe page.
    Route::get('{subscriber_uid}/resubscribe', [ListPagesController::class, 'resubscribeForm'])
        ->name('lists.public.resubscribe');

    Route::post('{subscriber_uid}/resubscribe', [ListPagesController::class, 'resubscribeProcess'])
        ->middleware('throttle:15,1')
        ->name('lists.public.resubscribe.process');

    // RFC 8058 one-click (F-1.5-015): mail providers POST directly with
    // body `List-Unsubscribe=One-Click` — no session, so no CSRF token.
    Route::post('{subscriber_uid}/unsubscribe-one-click', [ListPagesController::class, 'oneClickUnsubscribe'])
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->name('lists.public.unsubscribe.one_click');
});

// §1.6 — campaign public pages (content via CampaignWebViewSource; the
// Null default 404s until WP-11 binds the real implementation).
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('campaigns/reports/{secret}', [CampaignPagesController::class, 'report'])
        ->name('campaigns.public.report');

    Route::get('campaigns/{campaign_uid}/web-view', [CampaignPagesController::class, 'webView'])
        ->name('campaigns.public.web_view');

    Route::get('campaigns/{campaign_uid}/share', [CampaignPagesController::class, 'share'])
        ->name('campaigns.public.share');
});

// §1.7 — tracking endpoints (high-volume: generous but present rate limit).
Route::middleware('throttle:120,1')->group(function (): void {
    Route::get('campaigns/{campaign_uid}/track-opening/{subscriber_uid}', [TrackingController::class, 'open'])
        ->name('campaigns.track.open');

    Route::get('campaigns/{campaign_uid}/track-url/{subscriber_uid}/{url_hash}', [TrackingController::class, 'click'])
        ->name('campaigns.track.click');

    Route::get('campaigns/{campaign_uid}/track-unsubscribe/{subscriber_uid}', [TrackingController::class, 'unsubscribe'])
        ->name('campaigns.track.unsubscribe');
});

// §1.10 — email block request ("never email me again").
Route::get('email-block-request', [EmailBlockRequestController::class, 'form'])
    ->middleware('throttle:30,1')
    ->name('block_request.form');

Route::post('email-block-request', [EmailBlockRequestController::class, 'submit'])
    ->middleware('throttle:10,1')
    ->name('block_request.submit');

Route::get('email-block-request/{uid}/confirm', [EmailBlockRequestController::class, 'confirm'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('block_request.confirm');
