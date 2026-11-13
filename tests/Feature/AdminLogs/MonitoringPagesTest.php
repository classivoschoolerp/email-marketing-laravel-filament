<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\DeliveryServerUsagePage;
use App\Filament\Admin\Pages\ErrorLogPage;
use App\Filament\Admin\Pages\QueueMonitorPage;
use App\Filament\Admin\Pages\Settings\CronRunHistoryPage;
use App\Filament\Admin\Pages\SystemInfoPage;
use App\Filament\Admin\Widgets\ServerUsageChart;
use App\Models\CronRun;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\DeliveryServerUsage;
use App\Services\Reports\LogFileReader;
use App\Services\Reports\QueueMonitorService;
use App\Services\Reports\SystemInfoService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

// §3.27 F-3.27-004/007/008/013/014/015 — monitoring pages: server usage,
// cron history end column, error log viewer, queue monitor, system info +
// retention section, all gated by logs.view.

/* ================================================================ *
 *  F-3.27-004 — delivery server usage (global) + chart
 * ================================================================ */

it('shows global server usage rows with a per-server volume chart', function (): void {
    $serverA = DeliveryServer::factory()->create(['name' => 'Usage A']);
    $serverB = DeliveryServer::factory()->create(['name' => 'Usage B']);

    $a1 = DeliveryServerUsage::query()->create([
        'delivery_server_id' => $serverA->id, 'date' => now()->subDay()->toDateString(), 'sent' => 100, 'failed' => 5,
    ]);
    $a2 = DeliveryServerUsage::query()->create([
        'delivery_server_id' => $serverA->id, 'date' => now()->toDateString(), 'sent' => 50, 'failed' => 0,
    ]);
    $b1 = DeliveryServerUsage::query()->create([
        'delivery_server_id' => $serverB->id, 'date' => now()->toDateString(), 'sent' => 7, 'failed' => 1,
    ]);

    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(DeliveryServerUsagePage::getUrl(panel: 'admin'))->assertOk();

    Livewire::test(DeliveryServerUsagePage::class)
        ->assertCanSeeTableRecords([$a1, $a2, $b1])
        ->filterTable('delivery_server_id', $serverA->id)
        ->assertCanSeeTableRecords([$a1, $a2])
        ->assertCanNotSeeTableRecords([$b1]);

    // Chart: one dataset per server, exact daily sums.
    $chart = new ServerUsageChart;
    $data = (fn (): array => $this->getCachedData())->call($chart);

    $datasets = collect($data['datasets'])->keyBy('label');

    expect($datasets)->toHaveCount(2)
        ->and(array_sum($datasets['Usage A']['data']))->toBe(150)
        ->and(array_sum($datasets['Usage B']['data']))->toBe(7)
        ->and($data['labels'])->toContain(now()->format('Y-m-d'));
});

/* ================================================================ *
 *  F-3.27-007 — cron run logs: start, END, duration, processed, errors
 * ================================================================ */

it('shows finished timestamps on the cron run history', function (): void {
    CronRun::query()->create([
        'job' => 'stats:rollup',
        'status' => CronRun::STATUS_SUCCESS,
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
        'duration_ms' => 60000,
        'processed' => 42,
    ]);

    AdminTestHelpers::actingAdmin();

    Livewire::test(CronRunHistoryPage::class)
        ->assertTableColumnExists('finished_at')
        ->assertSee('stats:rollup')
        ->assertSee('42');
});

/* ================================================================ *
 *  F-3.27-008 — error log viewer
 * ================================================================ */

it('parses the log tail with severity filtering and stack folding', function (): void {
    $path = storage_path('framework/testing/wp17-error-log-test.log');
    @mkdir(dirname($path), 0777, true);

    file_put_contents($path, implode("\n", [
        '[2026-08-08 10:00:00] testing.INFO: routine info line',
        '[2026-08-08 10:01:00] testing.ERROR: something exploded',
        '#0 /app/Http/Kernel.php(1): boom()',
        '#1 {main}',
        '[2026-08-08 10:02:00] testing.WARNING: heads up',
        '',
    ]));

    $reader = app(LogFileReader::class);

    $all = $reader->tail($path);

    expect($all)->toHaveCount(3)
        ->and($all[0]['level'])->toBe('WARNING') // newest first
        ->and($all[1]['level'])->toBe('ERROR')
        ->and($all[1]['message'])->toBe('something exploded')
        ->and($all[1]['context'])->toBe("#0 /app/Http/Kernel.php(1): boom()\n#1 {main}")
        ->and($all[2]['message'])->toBe('routine info line');

    $errors = $reader->tail($path, 100, ['ERROR']);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['message'])->toBe('something exploded');

    // Big-file safety: only the tail window is parsed.
    file_put_contents($path, str_repeat('x', 2048)."\n".file_get_contents($path));
    $tailOnly = $reader->tail($path, 100, null, 512);

    expect(count($tailOnly))->toBeLessThanOrEqual(3);

    @unlink($path);
});

it('renders the error log page with the severity filter', function (): void {
    $marker = 'wp17-marker-'.uniqid();

    file_put_contents(
        storage_path('logs/laravel.log'),
        "\n[2026-08-08 11:00:00] testing.ERROR: {$marker}\n",
        FILE_APPEND,
    );

    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(ErrorLogPage::getUrl(panel: 'admin'))->assertOk()->assertSee($marker);

    Livewire::test(ErrorLogPage::class)
        ->assertSee($marker)
        ->set('level', 'ERROR')
        ->assertSee($marker)
        ->set('level', 'EMERGENCY')
        ->assertDontSee($marker);
});

/* ================================================================ *
 *  F-3.27-013 — queue monitor (honest D-04 alternative to Horizon)
 * ================================================================ */

it('reports per-queue depth from the jobs table', function (): void {
    config()->set('queue.default', 'database');

    DB::table('jobs')->insert([
        ['queue' => 'exports', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time() - 120],
        ['queue' => 'exports', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time() - 30],
        ['queue' => 'campaigns-send', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time(), 'available_at' => time(), 'created_at' => time() - 10],
    ]);

    $monitor = app(QueueMonitorService::class);
    $queues = collect($monitor->queues())->keyBy('queue');

    expect($monitor->supported())->toBeTrue()
        ->and($queues['exports']['pending'])->toBe(2)
        ->and($queues['exports']['reserved'])->toBe(0)
        ->and($queues['exports']['oldest_pending_seconds'])->toBeGreaterThanOrEqual(120)
        ->and($queues['campaigns-send']['pending'])->toBe(0)
        ->and($queues['campaigns-send']['reserved'])->toBe(1)
        ->and($queues['transactional']['pending'])->toBe(0)
        ->and($monitor->totalPending())->toBe(2)
        ->and($monitor->failedCount())->toBe(0);

    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(QueueMonitorPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('reports.admin.queue_monitor.per_queue'))
        ->assertSee('exports');
});

it('reports honestly when the queue driver has no inspectable tables', function (): void {
    config()->set('queue.default', 'sync');

    $monitor = app(QueueMonitorService::class);

    expect($monitor->supported())->toBeFalse()
        ->and($monitor->queues())->toBe([]);

    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(QueueMonitorPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('reports.admin.queue_monitor.unsupported', ['driver' => 'sync']));
});

/* ================================================================ *
 *  F-3.27-014/015 — system info + log retention section
 * ================================================================ */

it('reports versions, limits, drivers and retention values', function (): void {
    $info = app(SystemInfoService::class);

    expect($info->versions()['php'])->toBe(PHP_VERSION)
        ->and($info->versions()['laravel'])->toBe(app()->version())
        ->and($info->drivers()['database'])->toBe('sqlite')
        ->and($info->limits())->toHaveKey('memory_limit')
        ->and($info->extensions())->toContain('pdo_sqlite')
        ->and($info->databaseSizeBytes())->toBeNull() // :memory: has no file size — honest null
        ->and($info->retention()['cron_run_days'])->toBe(30)
        ->and($info->formatBytes(1536))->toBe('1.5 KB')
        ->and($info->formatBytes(null))->toBe('—');

    $disk = $info->diskSpace();

    expect($disk['free'])->toBeGreaterThan(0)
        ->and($disk['total'])->toBeGreaterThan(0);
});

it('renders the system info page with the retention section', function (): void {
    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(SystemInfoPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(PHP_VERSION)
        ->assertSee(__('reports.admin.system_info.retention'))
        ->assertSee(__('reports.admin.system_info.retention_keys.campaign_delivery_log_days'))
        ->assertSee(__('reports.admin.system_info.days', ['days' => 90]));
});

/* ================================================================ *
 *  Access control for every monitoring page
 * ================================================================ */

it('denies the monitoring pages without logs.view', function (): void {
    AdminTestHelpers::actingAdmin(); // no permission

    foreach ([DeliveryServerUsagePage::class, ErrorLogPage::class, QueueMonitorPage::class, SystemInfoPage::class] as $page) {
        $this->get($page::getUrl(panel: 'admin'))->assertForbidden();
    }
});

// A customer session never reaches the admin panel (bounced to admin login).
it('denies the monitoring pages to customer-guard users', function (): void {
    $this->actingAs(Customer::factory()->create(), 'customer');

    foreach ([DeliveryServerUsagePage::class, ErrorLogPage::class, QueueMonitorPage::class, SystemInfoPage::class] as $page) {
        $this->get($page::getUrl(panel: 'admin'))->assertRedirect();
    }
});
