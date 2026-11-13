<?php

declare(strict_types=1);

use App\Http\Controllers\Public\PrivacyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public compliance & privacy routes (WP-22, spec §4.5 / §3.25.16)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
| No auth anywhere: like the §1.5 pages, tenancy is derived from the list
| uid inside the controller. Every endpoint is rate-limited; the data export
| is additionally signed with a short TTL (the uid alone must not allow bulk
| scraping of personal data).
*/

// GDPR portability (F-4.5-001) — linked from the update-profile page.
Route::get('privacy/lists/{list_uid}/{subscriber_uid}/export', [PrivacyController::class, 'exportData'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('privacy.export');

// Right-to-erasure workflow (F-3.25.16-003) — 404s while the toggle is off.
Route::get('privacy/erasure', [PrivacyController::class, 'erasureForm'])
    ->middleware('throttle:30,1')
    ->name('privacy.erasure.form');

Route::post('privacy/erasure', [PrivacyController::class, 'erasureSubmit'])
    ->middleware('throttle:10,1')
    ->name('privacy.erasure.submit');

Route::get('privacy/erasure/{uid}/confirm', [PrivacyController::class, 'erasureConfirm'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('privacy.erasure.confirm');

// Cookie consent choice (F-3.25.16-001 / F-4.5-006).
Route::post('privacy/cookie-consent', [PrivacyController::class, 'cookieConsent'])
    ->middleware('throttle:60,1')
    ->name('privacy.cookie_consent');

// Legal pages: DPA text (F-3.25.16-004) + sub-processors (F-4.5-001).
Route::get('legal/dpa', [PrivacyController::class, 'dpa'])
    ->middleware('throttle:60,1')
    ->name('privacy.legal.dpa');

Route::get('legal/sub-processors', [PrivacyController::class, 'subProcessors'])
    ->middleware('throttle:60,1')
    ->name('privacy.legal.sub_processors');
