<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\PerformancePage;
use App\Models\SlowQuery;
use App\Services\Ops\IndexHealthChecker;
use App\Services\Ops\PerformanceMonitor;
use App\Settings\OpsSettings;
use App\Support\Ops\SlowQueryRecorder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

// §6.6 F-6.6-003 performance panel (slow queries, queue depth, worker count,
// cache hit rate) and F-6.6-004 index health.

function wp24SlowQueryEvent(string $sql, float $ms): QueryExecuted
{
    return new QueryExecuted($sql, [], $ms, DB::connection());
}

/* ================================================================ *
 *  F-6.6-003 slow-query recorder
 * ================================================================ */

it('records nothing while slow-query logging is off', function (): void {
    expect(SlowQueryRecorder::enabled())->toBeFalse();

    SlowQueryRecorder::handle(wp24SlowQueryEvent('select * from subscribers', 5000));

    expect(SlowQuery::query()->count())->toBe(0);
});

it('records queries over the threshold with a normalised fingerprint', function (): void {
    $settings = app(OpsSettings::class);
    $settings->slow_query_logging = true;
    $settings->slow_query_threshold_ms = 100;
    $settings->save();

    expect(SlowQueryRecorder::enabled())->toBeTrue()
        ->and(SlowQueryRecorder::thresholdMs())->toBe(100);

    SlowQueryRecorder::handle(wp24SlowQueryEvent('select * from subscribers where id = 1', 20));
    expect(SlowQuery::query()->count())->toBe(0); // under the threshold

    SlowQueryRecorder::handle(wp24SlowQueryEvent('select * from subscribers where id = 1', 250));
    SlowQueryRecorder::handle(wp24SlowQueryEvent('select * from subscribers where id = 987', 400));
    SlowQueryRecorder::handle(wp24SlowQueryEvent("select * from lists where name = 'x'", 300));

    expect(SlowQuery::query()->count())->toBe(3);

    // Same shape, different literals → one fingerprint.
    expect(SlowQuery::query()->distinct()->count('fingerprint'))->toBe(2)
        ->and(SlowQueryRecorder::fingerprint('SELECT * FROM t WHERE id = 5'))
        ->toBe(SlowQueryRecorder::fingerprint('select  *  from t where id = 99'));

    $summary = app(PerformanceMonitor::class)->slowQuerySummary();

    expect($summary['enabled'])->toBeTrue()
        ->and($summary['total'])->toBe(3)
        ->and($summary['last_24h'])->toBe(3)
        ->and($summary['slowest_ms'])->toBe(400);

    $top = app(PerformanceMonitor::class)->topSlowQueries();

    expect($top[0]['max_ms'])->toBe(400)
        ->and($top[0]['hits'])->toBe(2);
});

it('prunes slow queries past retention', function (): void {
    SlowQuery::query()->create([
        'fingerprint' => 'abc', 'sql' => 'select 1', 'time_ms' => 900,
        'created_at' => now()->subDays(30),
    ]);
    SlowQuery::query()->create([
        'fingerprint' => 'abc', 'sql' => 'select 1', 'time_ms' => 900,
        'created_at' => now(),
    ]);

    $monitor = app(PerformanceMonitor::class);

    expect($monitor->pruneSlowQueries(0))->toBe(0)   // 0 = keep forever (D-23)
        ->and($monitor->pruneSlowQueries(7))->toBe(1)
        ->and(SlowQuery::query()->count())->toBe(1);
});

/* ================================================================ *
 *  Queue depth / workers / cache hit rate — honest reporting
 * ================================================================ */

it('reports queue depth, worker count and an honest cache-hit-rate gap', function (): void {
    config()->set('queue.default', 'database');

    DB::table('jobs')->insert([
        ['queue' => 'exports', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time() - 60],
    ]);

    $summary = app(PerformanceMonitor::class)->summary();

    expect($summary['queues']['supported'])->toBeTrue()
        ->and($summary['queues']['total_pending'])->toBe(1)
        ->and($summary['workers']['alive'])->toBe(0)
        ->and($summary['workers']['cores'])->toBeGreaterThanOrEqual(1)
        ->and($summary['workers']['suggested']['total'])->toBeGreaterThanOrEqual(3)
        // The array cache driver exposes no counters — say so, never invent one.
        ->and($summary['cache']['supported'])->toBeFalse()
        ->and($summary['cache']['ratio'])->toBeNull()
        ->and($summary['cache']['note'])->toBe(__('ops.performance.cache_unsupported', ['driver' => 'array']));
});

/* ================================================================ *
 *  F-6.6-004 index health
 * ================================================================ */

it('checks expected composite indexes and reports unused-index detection honestly', function (): void {
    $checker = app(IndexHealthChecker::class);
    $report = $checker->report();

    expect($report['driver'])->toBe('sqlite')
        ->and($checker->isMysql())->toBeFalse()
        // WP-01/06 already ship the hot subscriber shape.
        ->and($report['missing'])->toBe([])
        ->and($report['unused']['supported'])->toBeFalse()
        ->and($report['unused']['note'])->toBe(__('ops.index_health.requires_mysql'))
        ->and($report['unused']['rows'])->toBe([]);

    $indexes = $checker->indexesFor('subscribers');

    expect($indexes)->not->toBeEmpty();

    $covered = collect($indexes)->contains(
        fn (array $columns): bool => array_slice($columns, 0, 4) === ['customer_id', 'list_id', 'status', 'email'],
    );

    expect($covered)->toBeTrue();
});

it('reports a missing index with the exact DDL to add it', function (): void {
    // Drop the WP-06 composite index and confirm the checker notices.
    DB::statement('drop index if exists subscribers_tenant_list_status_email');

    $report = app(IndexHealthChecker::class)->report();

    $missing = collect($report['missing'])->firstWhere('table', 'subscribers');

    expect($missing)->not->toBeNull()
        ->and($missing['columns'])->toBe(['customer_id', 'list_id', 'status', 'email'])
        ->and($missing['ddl'])->toContain('CREATE INDEX')
        ->and($missing['ddl'])->toContain('(customer_id, list_id, status, email)');

    $this->artisan('ops:index-health')
        ->expectsOutputToContain(__('ops.index_health.missing_heading'))
        ->assertFailed();
});

it('emits machine-readable index health output', function (): void {
    $this->artisan('ops:index-health --json')->assertSuccessful();
});

/* ================================================================ *
 *  Page
 * ================================================================ */

it('renders the performance page and clears recorded queries', function (): void {
    SlowQuery::query()->create([
        'fingerprint' => 'abc', 'sql' => 'select * from subscribers where email = ?',
        'time_ms' => 1234, 'created_at' => now(),
    ]);

    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(PerformancePage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('ops.performance.slow_queries'))
        ->assertSee(__('ops.index_health.heading'))
        ->assertSee('1234');

    Livewire::test(PerformancePage::class)->callAction('clearSlowQueries');

    expect(SlowQuery::query()->count())->toBe(0);
});
