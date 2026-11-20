<?php

declare(strict_types=1);

use App\Http\Controllers\Public\SurveyPublicController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Surveys module routes (WP-31, spec §2.8 + §1.9)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| Public only. Tenancy is never consulted: the survey uid and the responder
| uid are the access secrets (unguessable uuids, MailWizz pattern), so a uid
| belonging to another customer is simply a 404. `?s={subscriber_uid}` on the
| start URL identifies the respondent (F-1.9-002); it resolves inside the
| survey's tenant only, and a foreign uid degrades to an anonymous response
| rather than leaking that the subscriber exists.
|
| Every endpoint is rate-limited; the two write endpoints additionally carry
| the shared honeypot and the configured captcha.
*/

Route::prefix('surveys/{uid}')->group(function (): void {
    Route::get('/', [SurveyPublicController::class, 'start'])
        ->middleware('throttle:60,1')
        ->name('surveys.public.start');

    Route::post('start', [SurveyPublicController::class, 'begin'])
        ->middleware('throttle:15,1')
        ->name('surveys.public.begin');

    Route::get('r/{responder}/step/{step}', [SurveyPublicController::class, 'step'])
        ->whereNumber('step')
        ->middleware('throttle:60,1')
        ->name('surveys.public.step');

    Route::post('r/{responder}/step/{step}', [SurveyPublicController::class, 'saveStep'])
        ->whereNumber('step')
        ->middleware('throttle:30,1')
        ->name('surveys.public.step.save');

    Route::get('r/{responder}/thank-you', [SurveyPublicController::class, 'thanks'])
        ->middleware('throttle:60,1')
        ->name('surveys.public.thanks');
});
