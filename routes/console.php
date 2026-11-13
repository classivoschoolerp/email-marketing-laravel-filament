<?php

declare(strict_types=1);

use App\Jobs\Sending\FlushLiveCountersJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Optional modules register their own commands. A schedule entry whose
| command is not installed is skipped instead of failing every minute.
*/
$whenCommandExists = function (string $name): Closure {
    return function () use ($name): bool {
        try {
            return array_key_exists($name, Artisan::all());
        } catch (Throwable) {
            return false;
        }
    };
};

// WP-08 §2.2.8: purge export files past the §3.25.2 retention window daily.
Schedule::command('exports:prune')->daily();

/*
|--------------------------------------------------------------------------
| §0.6 cron jobs (WP-13, D-40 canonical names)
|--------------------------------------------------------------------------
| Every command body is wrapped in CronRunRecorder::record() and checks the
| §3.25.11 per-job enable toggle (RunsAsCronJob). The heartbeat is
| monitoring, not a schedulable batch job — it has no toggle (D-40) and is
| watched by HeartbeatMonitor (F-0.6-018) from the stats:rollup body.
*/

Schedule::command('system:cron-heartbeat')->everyMinute();

Schedule::command('campaigns:send')->everyMinute()->withoutOverlapping();
Schedule::command('campaigns:process-autoresponders')->everyMinute()->withoutOverlapping();
Schedule::command('campaigns:process-recurring')->everyMinute()->withoutOverlapping();
Schedule::command('automations:tick')->everyMinute()->withoutOverlapping()->when($whenCommandExists('automations:tick'));

Schedule::command('stats:rollup')->hourly();
Schedule::command('quotas:reset')->hourly();

Schedule::command('backups:run')->dailyAt('01:00');
Schedule::command('subscribers:clean')->dailyAt('02:00');
Schedule::command('logs:prune')->dailyAt('03:00');
Schedule::command('license:check')->dailyAt('04:00');

// Billing (§3.26, WP-23/WP-26): renewals + dunning run hourly so a failed
// charge retries inside its backoff window; credit and expiry notices daily.
Schedule::command('billing:process-renewals')->hourly()->withoutOverlapping()->when($whenCommandExists('billing:process-renewals'));
Schedule::command('billing:check-credits')->dailyAt('06:00')->when($whenCommandExists('billing:check-credits'));
// Lapsed subscriptions leaving their grace period (§2.15.6 F-2.15.6-010, WP-39).
Schedule::command('billing:process-expiries')->dailyAt('02:00')->when($whenCommandExists('billing:process-expiries'));

// CRM (§2.13, WP-44). Neither is a §0.6 canonical job (D-40): no KNOWN_JOBS
// entry, no per-job toggle, no CronRun row — same footing as ops:monitor.
Schedule::command('crm:recompute-lead-scores')->dailyAt('05:00')->when($whenCommandExists('crm:recompute-lead-scores'));
Schedule::command('crm:send-reminders')->everyFiveMinutes()->withoutOverlapping()->when($whenCommandExists('crm:send-reminders'));
Schedule::command('billing:check-expiring')->dailyAt('06:30')->when($whenCommandExists('billing:check-expiring'));

// Live stat counters → DB flush (spec §4.6 F-4.6-004, maintenance queue).
Schedule::job(new FlushLiveCountersJob)->everyFiveMinutes();

/*
| §0.6 jobs owned by parallel/later work packages (WP-15/16). Scheduled at
| their canonical cadence NOW so the scheduler layout is complete, but
| guarded: the event only fires once the owning WP registers the command.
*/
Schedule::command('bounces:process')->everyFiveMinutes()->when($whenCommandExists('bounces:process'));
Schedule::command('fbl:process')->everyFiveMinutes()->when($whenCommandExists('fbl:process'));
Schedule::command('mailbox-monitors:process')->everyTenMinutes()->when($whenCommandExists('mailbox-monitors:process'));
Schedule::command('warmup:advance')->daily()->when($whenCommandExists('warmup:advance'));
Schedule::command('sending-domains:verify')->daily()->when($whenCommandExists('sending-domains:verify'));
Schedule::command('tracking-domains:verify')->daily()->when($whenCommandExists('tracking-domains:verify'));
Schedule::command('blacklist-monitors:run')->daily()->when($whenCommandExists('blacklist-monitors:run'));
