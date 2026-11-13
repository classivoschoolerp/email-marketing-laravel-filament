<?php

declare(strict_types=1);

use App\Events\CampaignProgressUpdated;
use App\Services\Ops\CacheWarmer;
use App\Settings\OpsSettings;
use App\Support\Cache\SmartCache;
use App\Support\Files;
use App\Support\Ops\HorizonSupport;
use App\Support\Ops\ObjectStorage;
use App\Support\System\Cpu;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

// §0.1 remaining rows: F-0.1-005 Horizon (optional), F-0.1-006 Octane config,
// F-0.1-007 Reverb/Pusher broadcast event, F-0.1-009 S3 storage,
// F-0.1-015 CPU auto-detection, F-0.1-016 smart caching layer.

/* ================================================================ *
 *  F-0.1-015 CPU / worker auto-detection
 * ================================================================ */

it('detects CPU cores and suggests a worker layout', function (): void {
    Cpu::flush();

    $cores = Cpu::cores();
    $suggested = Cpu::suggestedWorkers();

    expect($cores)->toBeGreaterThanOrEqual(1)
        ->and(Cpu::source())->toBeIn(['cgroup', 'sysfs', 'procfs', 'env', 'fallback'])
        ->and($suggested['sending'])->toBeGreaterThanOrEqual(1)
        ->and($suggested['general'])->toBeGreaterThanOrEqual(1)
        ->and($suggested['scheduler'])->toBe(1)
        ->and($suggested['total'])->toBe($suggested['sending'] + $suggested['general'] + 1)
        // Never suggests more processes than cores + the scheduler.
        ->and($suggested['sending'] + $suggested['general'])->toBeLessThanOrEqual(max(1, $cores));
});

/* ================================================================ *
 *  F-0.1-016 smart caching layer
 * ================================================================ */

it('caches expensive values per bucket and honours the settings toggle', function (): void {
    $calls = 0;
    $callback = function () use (&$calls): int {
        $calls++;

        return 42;
    };

    expect(SmartCache::enabled())->toBeTrue()
        ->and(SmartCache::remember(SmartCache::BUCKET_DASHBOARD, 'answer', $callback))->toBe(42)
        ->and(SmartCache::remember(SmartCache::BUCKET_DASHBOARD, 'answer', $callback))->toBe(42)
        ->and($calls)->toBe(1);

    SmartCache::forget(SmartCache::BUCKET_DASHBOARD, 'answer');

    expect(SmartCache::remember(SmartCache::BUCKET_DASHBOARD, 'answer', $callback))->toBe(42)
        ->and($calls)->toBe(2);

    // Off → every call goes through to the callback.
    $settings = app(OpsSettings::class);
    $settings->smart_cache_enabled = false;
    $settings->save();

    SmartCache::remember(SmartCache::BUCKET_DASHBOARD, 'answer', $callback);
    SmartCache::remember(SmartCache::BUCKET_DASHBOARD, 'answer', $callback);

    expect($calls)->toBe(4)
        ->and(SmartCache::enabled())->toBeFalse();
});

it('warms the dashboard bucket and reports tag support honestly', function (): void {
    App\Models\Customer::factory()->count(3)->create();

    $warmer = app(CacheWarmer::class);
    $warmed = $warmer->warm();

    expect($warmed)->toHaveKey('counts.customers')
        ->and($warmed['counts.customers'])->toBe(3)
        ->and($warmer->count('customers'))->toBe(3);

    $this->artisan('emp:cache-warm')
        ->expectsOutputToContain(__('ops.cache.warmed', ['count' => count($warmed)]))
        ->assertSuccessful();

    // The array driver IS taggable; file/database are not and must say so.
    expect(SmartCache::taggable())->toBe(Illuminate\Support\Facades\Cache::store()->getStore() instanceof Illuminate\Cache\TaggableStore)
        ->and($warmer->flush())->toBe(SmartCache::taggable());
});

/* ================================================================ *
 *  F-0.1-009 S3-compatible object storage
 * ================================================================ */

it('resolves the upload disk from settings and round-trips a probe object', function (): void {
    // Off by default → the public disk, never a half-configured bucket.
    expect(ObjectStorage::diskName())->toBe(ObjectStorage::FALLBACK_DISK)
        ->and(ObjectStorage::enabled())->toBeFalse();

    $settings = app(OpsSettings::class);
    $settings->object_storage_enabled = true;
    $settings->object_storage_disk = 'local';
    $settings->save();

    expect(ObjectStorage::diskName())->toBe('local')
        ->and(ObjectStorage::enabled())->toBeTrue();

    // An unknown disk name falls back instead of throwing at upload time.
    $settings->object_storage_disk = 'not-a-disk';
    $settings->save();

    expect(ObjectStorage::diskName())->toBe(ObjectStorage::FALLBACK_DISK);

    $settings->object_storage_disk = 'local';
    $settings->save();

    $result = ObjectStorage::smokeTest();

    expect($result['ok'])->toBeTrue()
        ->and($result['disk'])->toBe('local')
        ->and($result['driver'])->toBe('local')
        ->and($result['error'])->toBeNull()
        // The probe cleans up after itself.
        ->and(collect(Storage::disk('local')->files('ops')))->toBeEmpty();

    $this->artisan('ops:storage-check')->assertSuccessful();
    $this->artisan('ops:storage-check --disk=does-not-exist')->assertFailed();
});

/* ================================================================ *
 *  F-0.1-007 Reverb / Pusher broadcast contract
 * ================================================================ */

it('defines the campaign progress broadcast shape', function (): void {
    $campaign = new class
    {
        public string $uid = 'camp-123';

        public int $delivery_total = 200;

        public int $delivery_sent = 150;

        public int $delivery_failed = 10;

        public string $status = 'sending';
    };

    $event = CampaignProgressUpdated::for($campaign);

    expect($event)->toBeInstanceOf(Illuminate\Contracts\Broadcasting\ShouldBroadcast::class)
        ->and($event->broadcastAs())->toBe('campaign.progress')
        ->and($event->broadcastOn()[0]->name)->toBe('private-campaigns.camp-123')
        ->and($event->percent())->toBe(80)
        ->and($event->broadcastWith())->toBe([
            'campaign_uid' => 'camp-123',
            'total' => 200,
            'sent' => 150,
            'failed' => 10,
            'status' => 'sending',
            'percent' => 80,
        ]);

    // Zero-total campaigns never divide by zero.
    expect((new CampaignProgressUpdated('x', 0, 0, 0, 'draft'))->percent())->toBe(0);

    // The documented one-line hook for the frozen send pipeline.
    Event::fake([CampaignProgressUpdated::class]);
    event(CampaignProgressUpdated::for($campaign));
    Event::assertDispatched(CampaignProgressUpdated::class, fn ($e): bool => $e->campaignUid === 'camp-123');
});

it('keeps broadcasting inert until a driver is configured', function (): void {
    expect(config('broadcasting.default'))->toBe('null')
        ->and(config('broadcasting.connections'))->toHaveKeys(['reverb', 'pusher', 'redis', 'log', 'null']);
});

/* ================================================================ *
 *  F-0.1-005 Horizon + F-0.1-006 Octane: prepared, honestly reported
 * ================================================================ */

it('reports the optional Horizon integration honestly', function (): void {
    $status = HorizonSupport::status();

    expect($status['installed'])->toBe(class_exists(Laravel\Horizon\Horizon::class))
        ->and($status['note'])->toBeString()
        ->and($status['note'])->not->toBe('');

    // configure() must be a safe no-op while the package is absent.
    HorizonSupport::configure();

    if (! $status['installed']) {
        expect(Illuminate\Support\Facades\Gate::has('viewHorizon'))->toBeFalse();
    }

    // Config ships ready for a Linux host.
    expect(config('horizon.defaults'))->toHaveKeys(['transactional', 'sending', 'ingest', 'maintenance'])
        ->and(config('horizon.defaults.sending.queue'))->toContain('campaigns-send');
});

it('ships an Octane config that never references the absent package', function (): void {
    expect(config('octane.server'))->toBe('frankenphp')
        ->and(config('octane.flush'))->toContain(App\Support\Tenancy\CurrentCustomer::class)
        ->and(config('octane.flush'))->toContain(App\Support\Roles\RoleChecker::class)
        ->and(config('octane.warm'))->toContain('db', 'cache', 'view');
});

/* ================================================================ *
 *  §6.9 F-6.9-004 open_basedir-safe file helper
 * ================================================================ */

it('normalises paths lexically and reports open_basedir denials', function (): void {
    expect(Files::normalize('/var/www/../www/emp//storage/./logs'))->toBe('/var/www/emp/storage/logs')
        ->and(Files::normalize('C:\\Users\\dev\\app\\..\\app'))->toBe('C:/Users/dev/app')
        ->and(Files::safeRelative('../../etc/passwd'))->toBe('etc/passwd')
        ->and(Files::safeRelative('/geoip/db.mmdb'))->toBe('geoip/db.mmdb')
        ->and(Files::contains('/var/www/emp', '/var/www/emp/storage'))->toBeTrue()
        ->and(Files::contains('/var/www/emp', '/var/www/empire'))->toBeFalse();

    // Storage paths can never escape their disk root.
    expect(Files::storagePath('../../../etc/passwd'))
        ->toBe(Files::normalize(Storage::disk('local')->path('etc/passwd')));

    // No open_basedir in this environment → everything is allowed, honestly.
    expect(Files::openBasedirEnabled())->toBe(trim((string) ini_get('open_basedir')) !== '')
        ->and(Files::accessible(base_path()))->toBeTrue();

    $inspection = Files::inspect(storage_path('logs'));

    expect($inspection['exists'])->toBeTrue()
        ->and($inspection['allowed'])->toBeTrue();

    $temp = Files::temporaryDirectory('wp24');

    expect(is_dir($temp))->toBeTrue()
        ->and(Files::contains(Files::normalize(storage_path('app/tmp')), $temp))->toBeTrue();
});
