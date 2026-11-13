<?php

declare(strict_types=1);

use App\Settings\CronSettings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

// §6.6 F-6.6-007 — "cron-driven scheduled tasks requiring no manual
// intervention". The proof is mechanical: every canonical §0.6 job (D-40)
// must be BOTH a registered artisan command and present in the schedule, so a
// single `schedule:run` cron entry drives the whole platform.

/** @return list<string> commands the schedule will invoke */
function wp24ScheduledCommands(): array
{
    $events = app(Schedule::class)->events();

    return collect($events)
        ->map(fn (Event $event): string => (string) $event->command)
        ->map(function (string $command): string {
            // '"…/php.exe" "artisan" name --opts' → 'name'
            $parts = preg_split('/\s+/', trim(str_replace(['"', "'"], '', $command))) ?: [];
            $index = array_search('artisan', $parts, true);

            return $index === false ? '' : (string) ($parts[$index + 1] ?? '');
        })
        ->filter()
        ->values()
        ->all();
}

it('registers and schedules every canonical cron job', function (): void {
    $registered = array_keys(Artisan::all());
    $scheduled = wp24ScheduledCommands();

    /*
     * Every D-40 canonical job now has an owning command. `mailbox-monitors:process`
     * (§2.9.6) and `warmup:advance` (§2.9.3) were the last two without one — WP-33
     * registered them, and the `when()` guards already sitting in routes/console.php
     * activated without a scheduler edit. This list is deliberately empty rather
     * than deleted: a future D-40 name added ahead of its work package belongs here,
     * and the assertion below keeps that state honest instead of silently passing.
     */
    $awaitingOwner = [];

    // Jobs owned by an optional module count once that module is installed.
    foreach (CronSettings::installedJobs() as $job) {
        expect($scheduled)->toContain($job);

        if (in_array($job, $awaitingOwner, true)) {
            expect($registered)->not->toContain($job); // still unowned — keep this honest
            continue;
        }

        expect($registered)->toContain($job);
    }

    // The monitoring commands are deliberately NOT KNOWN_JOBS (D-40 fixes that
    // list) but must still be scheduled — no manual intervention anywhere.
    expect($scheduled)->toContain('system:cron-heartbeat')
        ->and($scheduled)->toContain('ops:monitor')
        ->and($scheduled)->toContain('emp:cache-warm')
        ->and($scheduled)->toContain('geo:update-database')
        ->and($scheduled)->toContain('exports:prune');
});

it('keeps every scheduled §0.6 job toggleable from the cron settings', function (): void {
    $settings = app(CronSettings::class);

    expect($settings->enabled_jobs)->toBeArray();

    foreach ($settings->enabled_jobs as $job) {
        expect(CronSettings::KNOWN_JOBS)->toContain($job);
    }

    // Monitoring jobs have no toggle by design (D-40).
    foreach (['system:cron-heartbeat', 'ops:monitor', 'emp:cache-warm', 'geo:update-database'] as $monitoring) {
        expect(CronSettings::KNOWN_JOBS)->not->toContain($monitoring);
    }
});

it('runs each WP-24 scheduled command without manual input', function (): void {
    $this->artisan('ops:monitor')->assertSuccessful();
    $this->artisan('emp:cache-warm')->assertSuccessful();
    $this->artisan('geo:update-database')->assertSuccessful(); // auto-update off → no-op
    $this->artisan('emp:geo-import')->assertSuccessful();
    $this->artisan('queue:heartbeat', ['--once' => true])->assertSuccessful();
});
