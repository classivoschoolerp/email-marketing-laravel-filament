<?php

declare(strict_types=1);

use App\Http\Controllers\Ops\HealthController;
use App\Listeners\Ops\WorkerRegistryListener;
use App\Services\Geo\GeoProviderRegistry;
use App\Support\Ops\HorizonSupport;
use App\Support\Ops\SlowQueryRecorder;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Operations module (WP-24, spec §6.6 / §6.9 / §3.23 / §0.1)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| Boot-time wiring lives here for the same reason as D-41/D-51: the providers
| file is shared across parallel work packages, so registries/listeners are
| bound from the module's route file. THE SAME `route:cache` CAVEAT APPLIES —
| see the note at the bottom of this file.
*/

/*
 * §3.23 GeoIP provider registry (D-10), plugin-registrable (§6.1). Singleton
 * so a plugin's runtime register() call survives for the whole request.
 */
app()->singleton(GeoProviderRegistry::class);

/*
 * §6.6 F-6.6-003 slow-query recorder: installs a DB::listen hook ONLY when
 * the ops setting is on, so the default install pays nothing.
 */
SlowQueryRecorder::register();

/*
 * §6.6 F-6.6-008 worker/node registry from Laravel's queue events — no extra
 * daemon needed on a sending node.
 */
Event::listen(JobProcessing::class, [WorkerRegistryListener::class, 'handleJobProcessing']);
Event::listen(JobProcessed::class, [WorkerRegistryListener::class, 'handleJobProcessed']);
Event::listen(JobFailed::class, [WorkerRegistryListener::class, 'handleJobFailed']);

/*
 * §0.1 F-0.1-005 OPTIONAL Horizon dashboard gate (D-04). No-op unless the
 * package is installed — see App\Support\Ops\HorizonSupport + docs/tech-stack.md.
 */
HorizonSupport::configure();

/*
 * §6.9 F-6.9-007 health check for load balancers. Unauthenticated by design;
 * emits no credentials or paths. Laravel's `/up` remains the liveness probe.
 */
Route::get('health', HealthController::class)->name('ops.health');

/*
|--------------------------------------------------------------------------
| Schedules owned by this module
|--------------------------------------------------------------------------
| routes/console.php is frozen for parallel work packages, so WP-24's
| monitoring cadence is registered here. Console-only so a web request never
| pays for building schedule objects.
|
| These are monitoring/maintenance tasks, NOT §0.6 KNOWN_JOBS rows (D-40 fixes
| that list) — like `system:cron-heartbeat` they have no per-job toggle.
*/
if (app()->runningInConsole()) {
    Schedule::command('ops:monitor')->everyFiveMinutes()->withoutOverlapping();
    Schedule::command('emp:cache-warm')->hourly()->withoutOverlapping();
    Schedule::command('geo:update-database')->weeklyOn(3, '04:30');
}

/*
|--------------------------------------------------------------------------
| D-41 / D-51 follow-up (revisit scheduled for WP-24 — resolved here)
|--------------------------------------------------------------------------
| Registering bindings, listeners and schedules from route files makes
| `php artisan route:cache` unsafe: cached routes skip the route FILES, so
| none of the wiring above (nor AdminPanelExtensions in admin.php, nor the
| DriverRegistry singleton in sending.php) would run.
|
| Before enabling route caching in production, move every such block into a
| service provider. docs/install-vps.md documents this explicitly and the
| deploy scripts deliberately do NOT run `route:cache`.
*/
