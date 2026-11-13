<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\BackupsPage;
use App\Filament\Admin\Pages\OpsDashboardPage;
use App\Filament\Admin\Pages\PerformancePage;
use App\Filament\Admin\Pages\Settings\OpsSettingsPage;
use App\Filament\Admin\Pages\WorkerNodesPage;
use App\Models\CronRun;
use App\Models\Customer;
use App\Services\Ops\ResourceMonitor;
use App\Settings\OpsSettings;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

// §6.6 F-6.6-001/005 — the operations dashboard, the resource widgets and
// their thresholds, plus the admin-guard gate on every §6.6 page.

it('renders the operations dashboard with live system stats', function (): void {
    CronRun::query()->create([
        'job' => 'stats:rollup',
        'status' => CronRun::STATUS_SUCCESS,
        'started_at' => now()->subMinutes(3),
        'finished_at' => now()->subMinutes(2),
        'duration_ms' => 1200,
        'processed' => 17,
    ]);

    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(OpsDashboardPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(PHP_VERSION)
        ->assertSee('stats:rollup')
        ->assertSee(__('ops.dashboard.overview'));

    $page = Livewire::test(OpsDashboardPage::class)->instance();

    $performance = $page->performance();

    expect($performance)->toHaveKeys(['slow_queries', 'queues', 'workers', 'cache'])
        ->and($page->cpu()['cores'])->toBeGreaterThanOrEqual(1)
        ->and($page->cacheLayer()['driver'])->toBe('array')
        ->and($page->workers())->toHaveKeys(['alive', 'draining'])
        ->and($page->recentCronRuns()->pluck('job')->all())->toContain('stats:rollup');
});

/* ================================================================ *
 *  F-6.6-005 disk / memory / DB-size checks + thresholds
 * ================================================================ */

it('reports the three resource metrics with their thresholds', function (): void {
    $checks = collect(app(ResourceMonitor::class)->checks())->keyBy('key');

    expect($checks->keys()->all())->toBe(['disk', 'memory', 'database'])
        ->and($checks['disk']['status'])->toBeIn([
            ResourceMonitor::STATUS_OK,
            ResourceMonitor::STATUS_WARNING,
            ResourceMonitor::STATUS_UNKNOWN,
        ])
        // :memory: sqlite has no file size — reported honestly, not as 0.
        ->and($checks['database']['status'])->toBe(ResourceMonitor::STATUS_UNKNOWN)
        ->and($checks['database']['display'])->toBe('—')
        ->and($checks['memory']['value'])->not->toBeNull();
});

it('raises an admin notification once per metric per day when a threshold breaks', function (): void {
    $settings = app(OpsSettings::class);
    $settings->disk_free_percent_warning = 100;   // guarantees a breach
    $settings->memory_percent_warning = 0;        // guarantees a breach
    $settings->threshold_email_enabled = false;
    $settings->save();

    $admin = AdminTestHelpers::admin(['logs.view']);

    $monitor = app(ResourceMonitor::class);
    $breaches = $monitor->breaches();

    expect(collect($breaches)->pluck('key')->all())->toContain('disk', 'memory');

    $raised = $monitor->alert();

    expect($raised)->toContain('disk', 'memory')
        ->and(DB::table('notifications')->where('notifiable_id', $admin->id)->count())->toBe(2);

    // Deduplicated: a second sweep the same day adds nothing.
    expect($monitor->alert())->toBe([])
        ->and(DB::table('notifications')->where('notifiable_id', $admin->id)->count())->toBe(2);
});

it('emails threshold alerts when the setting is on', function (): void {
    Illuminate\Support\Facades\Mail::fake();

    $settings = app(OpsSettings::class);
    $settings->disk_free_percent_warning = 100;
    $settings->memory_percent_warning = 100;      // only disk should breach
    $settings->database_size_mb_warning = 999999;
    $settings->threshold_email_enabled = true;
    $settings->threshold_email_to = 'ops@example.test';
    $settings->save();

    AdminTestHelpers::admin(['logs.view']);

    expect(app(ResourceMonitor::class)->alert())->toBe(['disk']);

    Illuminate\Support\Facades\Mail::assertQueued(
        App\Mail\Ops\ThresholdAlertMail::class,
        fn ($mail): bool => $mail->hasTo('ops@example.test') && $mail->check['key'] === 'disk',
    );
});

/* ================================================================ *
 *  Ops settings page
 * ================================================================ */

it('saves the operations settings group', function (): void {
    AdminTestHelpers::actingAdmin();

    $this->get(OpsSettingsPage::getUrl(panel: 'admin'))->assertOk();

    Livewire::test(OpsSettingsPage::class)
        ->fillForm([
            'error_notifications_enabled' => true,
            'error_notification_level' => 'CRITICAL',
            'slow_query_logging' => true,
            'slow_query_threshold_ms' => 250,
            'slow_query_retention_days' => 3,
            'disk_free_percent_warning' => 20,
            'memory_percent_warning' => 90,
            'database_size_mb_warning' => 2048,
            'threshold_email_enabled' => false,
            'threshold_email_to' => null,
            'backup_include_uploads' => false,
            'backup_retention_days' => 14,
            'backup_time' => '02:30',
            'worker_stale_seconds' => 90,
            'smart_cache_enabled' => true,
            'smart_cache_ttl_seconds' => 120,
            'object_storage_enabled' => false,
            'object_storage_disk' => 'local',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(OpsSettings::class)->refresh();

    expect($settings->error_notification_level)->toBe('CRITICAL')
        ->and($settings->slow_query_threshold_ms)->toBe(250)
        ->and($settings->backup_retention_days)->toBe(14)
        ->and($settings->worker_stale_seconds)->toBe(90);
});

it('rejects an invalid backup time and an unknown disk', function (): void {
    AdminTestHelpers::actingAdmin();

    Livewire::test(OpsSettingsPage::class)
        ->fillForm(['backup_time' => '99:99', 'object_storage_disk' => 'nope'])
        ->call('save')
        ->assertHasFormErrors(['backup_time', 'object_storage_disk']);
});

/* ================================================================ *
 *  Access control across every §6.6 page
 * ================================================================ */

it('denies the operations pages without logs.view', function (): void {
    AdminTestHelpers::actingAdmin();

    foreach ([OpsDashboardPage::class, PerformancePage::class, BackupsPage::class, WorkerNodesPage::class] as $page) {
        $this->get($page::getUrl(panel: 'admin'))->assertForbidden();
    }
});

it('denies the operations pages to customer-guard users', function (): void {
    $this->actingAs(Customer::factory()->create(), 'customer');

    foreach ([OpsDashboardPage::class, PerformancePage::class, BackupsPage::class, WorkerNodesPage::class] as $page) {
        $this->get($page::getUrl(panel: 'admin'))->assertRedirect();
    }
});
