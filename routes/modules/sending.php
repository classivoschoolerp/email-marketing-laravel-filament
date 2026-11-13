<?php

declare(strict_types=1);

use App\Http\Controllers\Sending\ActivateDeliveryServerController;
use App\Sending\DriverRegistry;
use App\Sending\Support\NativePhpMailer;
use App\Sending\Support\PhpMailer;
use App\Services\SpamCheck\NullChecker;
use App\Services\SpamCheck\SpamAssassinChecker;
use App\Services\SpamCheck\SpamCheckerInterface;
use App\Services\SpamCheck\TcpSpamdClient;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sending module routes + bindings (WP-14, spec §2.9.2)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| Container wiring lives here because the providers file is frozen mid-wave
| (same boot-time precedent as D-41 / AdminPanelExtensions). Move into a
| dedicated provider before enabling route caching in production.
*/

if (! app()->bound(DriverRegistry::class)) {
    // Singleton so plugin register() calls (§6.1) persist for the process.
    app()->singleton(DriverRegistry::class);

    app()->bind(PhpMailer::class, NativePhpMailer::class);

    app()->bind(SpamCheckerInterface::class, function (): SpamCheckerInterface {
        if (! config('emp-sending.spamassassin.enabled', false)) {
            return new NullChecker;
        }

        return new SpamAssassinChecker(new TcpSpamdClient(
            (string) config('emp-sending.spamassassin.host', '127.0.0.1'),
            (int) config('emp-sending.spamassassin.port', 783),
            (int) config('emp-sending.spamassassin.timeout', 10),
        ));
    });
}

// F-2.9.2-041 — public activation link from the validation email. Signature
// + random token guard it; throttled against token guessing.
Route::get('/delivery-servers/{server}/activate/{token}', ActivateDeliveryServerController::class)
    ->middleware(['signed', 'throttle:10,1'])
    ->whereNumber('server')
    ->name('sending.servers.activate');
