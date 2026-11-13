<?php

declare(strict_types=1);

use App\Console\Commands\CronHeartbeat;
use App\Enums\DeliveryServerStatus;
use App\Enums\SubscriberStatus;
use App\Jobs\Sending\FlushLiveCountersJob;
use App\Mail\CronHeartbeatStaleMail;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignStatsDaily;
use App\Models\CampaignTrackOpen;
use App\Models\CronRun;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Models\User;
use App\Settings\CronSettings;
use App\Settings\LicenseSettings;
use App\Support\Stats\LiveCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
 * §0.6 maintenance cron jobs (WP-13): stats:rollup, subscribers:clean,
 * logs:prune, quotas:reset, license:check, backups:run, automations:tick,
 * the D-40 enable-toggle + CronRunRecorder wrapper, the schedule
 * registration, the live-counter flush and the heartbeat alert.
 */

it('registers every D-40 canonical job on the scheduler', function (): void {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($event): string => (string) ($event->command ?? $event->description ?? ''));

    foreach (CronSettings::KNOWN_JOBS as $job) {
        expect($events->contains(fn (string $command): bool => str_contains($command, $job)))
            ->toBeTrue("cron job {$job} is not scheduled");
    }

    // Heartbeat + counter flush ride along.
    expect($events->contains(fn (string $c): bool => str_contains($c, 'system:cron-heartbeat')))->toBeTrue()
        ->and($events->contains(fn (string $c): bool => str_contains($c, FlushLiveCountersJob::class)))->toBeTrue();
});

it('records cron runs and honors the per-job enable toggle (D-40)', function (): void {
    $this->artisan('campaigns:process-recurring')->assertSuccessful();

    $run = CronRun::query()->where('job', 'campaigns:process-recurring')->latest('id')->firstOrFail();

    expect($run->status)->toBe(CronRun::STATUS_SUCCESS)
        ->and($run->processed)->toBe(0)
        ->and($run->finished_at)->not->toBeNull();

    // Disabled job: skipped, no run row.
    $settings = app(CronSettings::class);
    $settings->enabled_jobs = array_values(array_diff(CronSettings::KNOWN_JOBS, ['campaigns:process-recurring']));
    $settings->save();

    $before = CronRun::query()->count();
    $this->artisan('campaigns:process-recurring')->assertSuccessful();

    expect(CronRun::query()->count())->toBe($before);
});

it('rolls up delivery and tracking stats into campaign_stats_daily', function (): void {
    Mail::fake();
    Cache::put(CronHeartbeat::CACHE_KEY, now()->toIso8601String());

    $list = EmailList::factory()->create();
    $campaign = Campaign::factory()->create([
        'customer_id' => $list->customer_id,
        'email_list_id' => $list->id,
    ]);
    $subscribers = Subscriber::factory()->count(2)->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
    ]);

    foreach ($subscribers as $subscriber) {
        CampaignDeliveryLog::query()->create([
            'campaign_id' => $campaign->id,
            'subscriber_id' => $subscriber->id,
            'status' => 'sent',
        ]);
    }

    // Track rows arrive from WP-09 with campaign_id NULL — backfilled here.
    CampaignTrackOpen::query()->create([
        'campaign_uid' => $campaign->uid,
        'subscriber_uid' => $subscribers[0]->uid,
        'created_at' => now(),
    ]);
    CampaignTrackOpen::query()->create([
        'campaign_uid' => $campaign->uid,
        'subscriber_uid' => $subscribers[0]->uid,
        'created_at' => now(),
    ]);

    $this->artisan('stats:rollup')->assertSuccessful();

    $row = CampaignStatsDaily::query()
        ->where('campaign_id', $campaign->id)
        ->whereDate('day', now()->toDateString())
        ->firstOrFail();

    expect((int) $row->sent)->toBe(2)
        ->and((int) $row->opens)->toBe(2)
        ->and((int) $row->unique_opens)->toBe(1)
        ->and(CampaignTrackOpen::query()->whereNull('campaign_id')->count())->toBe(0)
        ->and(CronRun::query()->where('job', 'stats:rollup')->where('status', 'success')->exists())->toBeTrue();

    // Idempotent: a second run does not double-count.
    $this->artisan('stats:rollup')->assertSuccessful();
    expect((int) $row->refresh()->sent)->toBe(2);
});

it('purges unconfirmed subscribers past the list auto-delete window (F-2.2.2-019)', function (): void {
    $list = EmailList::factory()->create(['autodelete_unconfirmed_days' => 3]);

    $stale = Subscriber::factory()->unconfirmed()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'subscribed_at' => now()->subDays(5),
    ]);
    $fresh = Subscriber::factory()->unconfirmed()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'subscribed_at' => now()->subDay(),
    ]);
    $confirmedOld = Subscriber::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'subscribed_at' => now()->subDays(30),
    ]);

    // A list with the feature off keeps everything.
    $otherList = EmailList::factory()->create(['autodelete_unconfirmed_days' => 0]);
    $untouched = Subscriber::factory()->unconfirmed()->create([
        'list_id' => $otherList->id,
        'customer_id' => $otherList->customer_id,
        'subscribed_at' => now()->subDays(90),
    ]);

    $this->artisan('subscribers:clean')->assertSuccessful();

    expect(Subscriber::withoutTenancy()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(Subscriber::withoutTenancy()->whereKey($fresh->id)->exists())->toBeTrue()
        ->and(Subscriber::withoutTenancy()->whereKey($confirmedOld->id)->exists())->toBeTrue()
        ->and(Subscriber::withoutTenancy()->whereKey($untouched->id)->exists())->toBeTrue()
        ->and(CronRun::query()->where('job', 'subscribers:clean')->value('processed'))->toBe(1);
});

// F-2.2.2-021 — the double opt-in confirmation expiry is enforced in HOURS,
// independently of the day-based auto-delete window.
it('purges unconfirmed subscribers past the list opt-in confirmation expiry (F-2.2.2-021)', function (): void {
    $list = EmailList::factory()->create([
        'optin_confirm_expiry_hours' => 48,
        'autodelete_unconfirmed_days' => 0,
    ]);

    $expired = Subscriber::factory()->unconfirmed()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'subscribed_at' => now()->subHours(72),
    ]);
    $stillValid = Subscriber::factory()->unconfirmed()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'subscribed_at' => now()->subHours(12),
    ]);
    // Confirmed rows are never touched, however old.
    $confirmed = Subscriber::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'subscribed_at' => now()->subYear(),
    ]);

    // Expiry disabled on this list → nothing removed.
    $offList = EmailList::factory()->create(['optin_confirm_expiry_hours' => 0]);
    $untouched = Subscriber::factory()->unconfirmed()->create([
        'list_id' => $offList->id,
        'customer_id' => $offList->customer_id,
        'subscribed_at' => now()->subYear(),
    ]);

    $this->artisan('subscribers:clean')->assertSuccessful();

    expect(Subscriber::withoutTenancy()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(Subscriber::withoutTenancy()->whereKey($stillValid->id)->exists())->toBeTrue()
        ->and(Subscriber::withoutTenancy()->whereKey($confirmed->id)->exists())->toBeTrue()
        ->and(Subscriber::withoutTenancy()->whereKey($untouched->id)->exists())->toBeTrue()
        ->and(CronRun::query()->where('job', 'subscribers:clean')->value('processed'))->toBe(1);

    // Time travel past the shorter window: the once-valid row now expires too.
    $this->travel(60)->hours();
    $this->artisan('subscribers:clean')->assertSuccessful();

    expect(Subscriber::withoutTenancy()->whereKey($stillValid->id)->exists())->toBeFalse()
        ->and(Subscriber::withoutTenancy()->whereKey($untouched->id)->exists())->toBeTrue();

    $this->travelBack();
});

// F-2.2.2-020 — auto-remove after N hard bounces, counted from the WP-15
// per-message logs and from the subscriber's own hard-bounce counter.
it('removes subscribers past the list hard-bounce threshold (F-2.2.2-020)', function (): void {
    $list = EmailList::factory()->create(['autoremove_bounces_count' => 3]);

    $make = fn (array $attributes = []): Subscriber => Subscriber::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
    ] + $attributes);

    $logged = $make();          // 3 hard bounce log rows → removed
    $justUnder = $make();       // 2 hard bounce log rows → kept
    $softOnly = $make();        // 5 soft bounce log rows → kept
    $counterOnly = $make(['bounce_type' => 'hard', 'bounces_count' => 4]); // → removed
    $softCounter = $make(['bounce_type' => 'soft', 'bounces_count' => 9]); // → kept
    $clean = $make();           // no bounces at all → kept

    $bounce = function (Subscriber $subscriber, string $type, int $times) use ($list): void {
        for ($i = 0; $i < $times; $i++) {
            DB::table('campaign_bounce_logs')->insert([
                'customer_id' => $list->customer_id,
                'subscriber_id' => $subscriber->id,
                'bounce_type' => $type,
                'email' => $subscriber->email,
                'created_at' => now(),
            ]);
        }
    };

    $bounce($logged, 'hard', 3);
    $bounce($justUnder, 'hard', 2);
    $bounce($softOnly, 'soft', 5);

    // A list with the feature off keeps its hard-bounced subscribers.
    $offList = EmailList::factory()->create(['autoremove_bounces_count' => 0]);
    $offListSubscriber = Subscriber::factory()->create([
        'list_id' => $offList->id,
        'customer_id' => $offList->customer_id,
        'bounce_type' => 'hard',
        'bounces_count' => 99,
    ]);

    $this->artisan('subscribers:clean')->assertSuccessful();

    expect(Subscriber::withoutTenancy()->whereKey($logged->id)->exists())->toBeFalse()
        ->and(Subscriber::withoutTenancy()->whereKey($counterOnly->id)->exists())->toBeFalse()
        ->and(Subscriber::withoutTenancy()->whereKey($justUnder->id)->exists())->toBeTrue()
        ->and(Subscriber::withoutTenancy()->whereKey($softOnly->id)->exists())->toBeTrue()
        ->and(Subscriber::withoutTenancy()->whereKey($softCounter->id)->exists())->toBeTrue()
        ->and(Subscriber::withoutTenancy()->whereKey($clean->id)->exists())->toBeTrue()
        ->and(Subscriber::withoutTenancy()->whereKey($offListSubscriber->id)->exists())->toBeTrue()
        ->and(CronRun::query()->where('job', 'subscribers:clean')->value('processed'))->toBe(2);

    // One more hard bounce pushes the near-miss over the threshold.
    $bounce($justUnder, 'hard', 1);

    $this->artisan('subscribers:clean')->assertSuccessful();

    expect(Subscriber::withoutTenancy()->whereKey($justUnder->id)->exists())->toBeFalse();
});

it('prunes aged delivery, track, cron-run and idempotency rows', function (): void {
    $list = EmailList::factory()->create();
    $campaign = Campaign::factory()->create([
        'customer_id' => $list->customer_id,
        'email_list_id' => $list->id,
    ]);
    $subscriber = Subscriber::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
    ]);

    $old = CampaignDeliveryLog::query()->create([
        'campaign_id' => $campaign->id,
        'subscriber_id' => $subscriber->id,
        'status' => 'sent',
    ]);
    DB::table('campaign_delivery_logs')->where('id', $old->id)->update(['created_at' => now()->subDays(200)]);

    CampaignTrackOpen::query()->create([
        'campaign_uid' => $campaign->uid,
        'subscriber_uid' => $subscriber->uid,
        'created_at' => now()->subDays(400),
    ]);
    CampaignTrackOpen::query()->create([
        'campaign_uid' => $campaign->uid,
        'subscriber_uid' => $subscriber->uid,
        'created_at' => now(),
    ]);

    DB::table('cron_runs')->insert([
        'job' => 'ancient', 'status' => 'success',
        'started_at' => now()->subDays(90), 'created_at' => now()->subDays(90), 'updated_at' => now()->subDays(90),
    ]);
    DB::table('job_idempotency_keys')->insert(['key' => 'send:old:key', 'created_at' => now()->subDays(90)]);
    DB::table('job_idempotency_keys')->insert(['key' => 'send:new:key', 'created_at' => now()]);

    $this->artisan('logs:prune')->assertSuccessful();

    expect(CampaignDeliveryLog::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(CampaignTrackOpen::query()->count())->toBe(1)
        ->and(DB::table('cron_runs')->where('job', 'ancient')->exists())->toBeFalse()
        ->and(DB::table('job_idempotency_keys')->where('key', 'send:old:key')->exists())->toBeFalse()
        ->and(DB::table('job_idempotency_keys')->where('key', 'send:new:key')->exists())->toBeTrue();
});

it('refreshes hourly-quota-reached servers back to active (quotas:reset)', function (): void {
    $server = DeliveryServer::factory()->create([
        'status' => DeliveryServerStatus::HourlyQuotaReached,
        'hourly_quota' => 0, // unlimited — window rolled
    ]);

    DB::table('delivery_server_usage')->insert([
        'delivery_server_id' => $server->id,
        'date' => now()->subMonths(20)->toDateString(),
        'sent' => 10, 'failed' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('quotas:reset')->assertSuccessful();

    expect($server->refresh()->status)->toBe(DeliveryServerStatus::Active)
        ->and(DB::table('delivery_server_usage')->where('delivery_server_id', $server->id)->count())->toBe(0)
        ->and(CronRun::query()->where('job', 'quotas:reset')->value('processed'))->toBe(1);
});

it('re-validates the license daily (self-hosted without an endpoint)', function (): void {
    config(['license.endpoint' => null]);

    $this->artisan('license:check')->assertSuccessful();

    $settings = app(LicenseSettings::class)->refresh();

    expect($settings->status)->toBe(LicenseSettings::STATUS_VALID)
        ->and($settings->last_checked_at)->not->toBeNull()
        ->and(CronRun::query()->where('job', 'license:check')->where('status', 'success')->exists())->toBeTrue();
});

it('backs up a sqlite database file and prunes old backups', function (): void {
    $scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'emp-backup-test-'.uniqid();
    File::ensureDirectoryExists($scratch);

    $dbFile = $scratch.DIRECTORY_SEPARATOR.'source.sqlite';
    File::put($dbFile, 'SQLITE-CONTENT');

    // The live test connection (:memory:) is already established — pointing
    // the config at a real file only affects what backups:run copies.
    config([
        'database.connections.sqlite.database' => $dbFile,
        'emp-sending.backups.directory' => $scratch,
    ]);

    $stale = $scratch.DIRECTORY_SEPARATOR.'backup-20200101-000000.sqlite';
    File::put($stale, 'old');
    touch($stale, now()->subDays(30)->getTimestamp());

    $this->artisan('backups:run')->assertSuccessful();

    $backups = collect(File::files($scratch))
        ->filter(fn ($file): bool => str_starts_with($file->getFilename(), 'backup-') && $file->getFilename() !== 'backup-20200101-000000.sqlite');

    expect($backups)->toHaveCount(1)
        ->and(File::get($backups->first()->getPathname()))->toBe('SQLITE-CONTENT')
        ->and(File::exists($stale))->toBeFalse()
        ->and(CronRun::query()->where('job', 'backups:run')->where('status', 'success')->exists())->toBeTrue();

    File::deleteDirectory($scratch);
});

it('records a failed run when the database cannot be backed up', function (): void {
    config(['emp-sending.backups.directory' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'emp-backup-'.uniqid()]);
    // :memory: default — nothing to copy.

    $this->artisan('backups:run')->assertFailed();

    $run = CronRun::query()->where('job', 'backups:run')->latest('id')->firstOrFail();

    expect($run->status)->toBe(CronRun::STATUS_FAILED)
        ->and((string) $run->error)->not->toBe('');
});

it('flushes live counters by recomputing the touched campaigns rollup', function (): void {
    $list = EmailList::factory()->create();
    $campaign = Campaign::factory()->create([
        'customer_id' => $list->customer_id,
        'email_list_id' => $list->id,
    ]);

    CampaignTrackOpen::query()->create([
        'campaign_uid' => $campaign->uid,
        'campaign_id' => $campaign->id,
        'subscriber_uid' => 'sub-x',
        'created_at' => now(),
    ]);

    $counter = app(LiveCounter::class);
    $counter->increment('campaigns:'.$campaign->uid.':opens');
    $counter->increment('track:opens');

    dispatch(new FlushLiveCountersJob);

    expect($counter->value('campaigns:'.$campaign->uid.':opens'))->toBe(0)
        ->and((int) CampaignStatsDaily::query()->where('campaign_id', $campaign->id)->value('opens'))->toBe(1);
});

it('alerts admins when the cron heartbeat goes stale (F-0.6-018)', function (): void {
    Mail::fake();

    User::factory()->create(['email' => 'admin@example.com']);

    Cache::forget(CronHeartbeat::CACHE_KEY);
    Cache::forget(\App\Services\Cron\HeartbeatMonitor::COOLDOWN_CACHE_KEY);

    $this->artisan('stats:rollup')->assertSuccessful();

    Mail::assertQueued(CronHeartbeatStaleMail::class, 1);

    // Cooldown: the next check does not re-alert.
    $this->artisan('stats:rollup')->assertSuccessful();
    Mail::assertQueued(CronHeartbeatStaleMail::class, 1);

    // A fresh heartbeat silences the alert entirely.
    Cache::forget(\App\Services\Cron\HeartbeatMonitor::COOLDOWN_CACHE_KEY);
    $this->artisan('system:cron-heartbeat')->assertSuccessful();
    $this->artisan('stats:rollup')->assertSuccessful();
    Mail::assertQueued(CronHeartbeatStaleMail::class, 1);
});
