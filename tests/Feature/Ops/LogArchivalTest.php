<?php

declare(strict_types=1);

use App\Models\LogArchive;
use App\Services\Ops\LogArchiver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * WP-42 — F-4.6-008 archival / cold storage for old logs.
 *
 * `logs:prune` still enforces the same retention windows; with archiving on
 * it writes each batch to a filesystem disk before deleting it, indexes the
 * file in `log_archives`, and `logs:restore-archive` reads it back.
 */

beforeEach(function (): void {
    Storage::fake('archive');

    config([
        'emp-archive.enabled' => true,
        'emp-archive.disk' => 'archive',
        'emp-archive.path' => 'cold',
        'emp-archive.chunk' => 2,
        // Isolate the assertions: only the login_logs window is open.
        'emp-sending.retention.campaign_delivery_log_days' => 0,
        'emp-sending.retention.campaign_track_days' => 0,
        'emp-sending.retention.cron_run_days' => 0,
        'emp-sending.retention.idempotency_key_days' => 0,
    ]);
});

/** Insert a login_logs row with an exact created_at. */
function archivableLoginLog(string $email, Carbon $at): int
{
    return (int) DB::table('login_logs')->insertGetId([
        'guard' => 'customer',
        'email' => $email,
        'ip' => '203.0.113.9',
        'user_agent' => 'phpunit',
        'result' => 'success',
        'created_at' => $at,
    ]);
}

it('writes old rows to cold storage before deleting them (F-4.6-008)', function (): void {
    $old = Carbon::now()->subDays(400);

    $keptId = archivableLoginLog('recent@x.test', Carbon::now()->subDay());
    archivableLoginLog('old-a@x.test', $old);
    archivableLoginLog('old-b@x.test', $old->copy()->addMinute());
    archivableLoginLog('old-c@x.test', $old->copy()->addMinutes(2));

    $this->artisan('logs:prune')->assertExitCode(0);

    // The hot table keeps only what is inside the window.
    expect(DB::table('login_logs')->pluck('id')->all())->toBe([$keptId]);

    // chunk = 2 → two archive files for three rows.
    $archives = LogArchive::query()->where('table_name', 'login_logs')->orderBy('id')->get();

    expect($archives)->toHaveCount(2)
        ->and($archives->sum('rows_count'))->toBe(3)
        ->and($archives->first()->disk)->toBe('archive')
        ->and($archives->first()->path)->toStartWith('cold/login_logs/')
        ->and($archives->first()->path)->toEndWith('.ndjson.gz')
        ->and($archives->first()->bytes)->toBeGreaterThan(0);

    foreach ($archives as $archive) {
        Storage::disk('archive')->assertExists($archive->path);
    }

    // The bytes really are the rows: gunzip + parse NDJSON.
    $emails = $archives
        ->flatMap(function (LogArchive $archive): array {
            $body = gzdecode(Storage::disk('archive')->get($archive->path));

            return array_map(
                static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                array_values(array_filter(explode("\n", (string) $body), static fn (string $l): bool => trim($l) !== '')),
            );
        })
        ->pluck('email')
        ->sort()
        ->values()
        ->all();

    expect($emails)->toBe(['old-a@x.test', 'old-b@x.test', 'old-c@x.test']);
});

it('records the archived period bounds (F-4.6-008)', function (): void {
    config(['emp-archive.chunk' => 100]);

    $first = Carbon::now()->subDays(500)->startOfSecond();
    $last = Carbon::now()->subDays(400)->startOfSecond();

    archivableLoginLog('a@x.test', $first);
    archivableLoginLog('b@x.test', $last);

    $this->artisan('logs:prune')->assertExitCode(0);

    $archive = LogArchive::query()->where('table_name', 'login_logs')->sole();

    expect($archive->rows_count)->toBe(2)
        ->and($archive->period_start->toDateTimeString())->toBe($first->toDateTimeString())
        ->and($archive->period_end->toDateTimeString())->toBe($last->toDateTimeString())
        ->and($archive->restored_at)->toBeNull();
});

it('prunes without archiving when the feature is off (F-4.6-008)', function (): void {
    config(['emp-archive.enabled' => false]);

    archivableLoginLog('old@x.test', Carbon::now()->subDays(400));

    $this->artisan('logs:prune')->assertExitCode(0);

    expect(DB::table('login_logs')->count())->toBe(0)
        ->and(LogArchive::query()->count())->toBe(0);

    expect(Storage::disk('archive')->allFiles())->toBe([]);
});

it('never archives a table that is not on the allow-list (F-4.6-008)', function (): void {
    config(['emp-archive.tables' => ['campaign_track_opens']]);

    archivableLoginLog('old@x.test', Carbon::now()->subDays(400));

    $this->artisan('logs:prune')->assertExitCode(0);

    // Still pruned, just not archived.
    expect(DB::table('login_logs')->count())->toBe(0)
        ->and(LogArchive::query()->where('table_name', 'login_logs')->count())->toBe(0);

    expect(app(LogArchiver::class)->archives('login_logs'))->toBeFalse()
        ->and(app(LogArchiver::class)->archives('campaign_track_opens'))->toBeTrue();
});

it('restores an archive back into its table and is idempotent (F-4.6-008)', function (): void {
    config(['emp-archive.chunk' => 100]);

    $at = Carbon::now()->subDays(400);
    archivableLoginLog('old-a@x.test', $at);
    archivableLoginLog('old-b@x.test', $at);

    $this->artisan('logs:prune')->assertExitCode(0);

    expect(DB::table('login_logs')->count())->toBe(0);

    $archive = LogArchive::query()->sole();

    $inserted = app(LogArchiver::class)->restore($archive);

    expect($inserted)->toBe(2)
        ->and(DB::table('login_logs')->pluck('email')->sort()->values()->all())
        ->toBe(['old-a@x.test', 'old-b@x.test'])
        ->and($archive->refresh()->restored_at)->not->toBeNull();

    // Restoring again inserts nothing and throws nothing.
    expect(app(LogArchiver::class)->restore($archive->refresh()))->toBe(0)
        ->and(DB::table('login_logs')->count())->toBe(2);
});

it('restores through the console command and lists what is available (F-4.6-008)', function (): void {
    config(['emp-archive.chunk' => 100]);

    archivableLoginLog('old@x.test', Carbon::now()->subDays(400));

    $this->artisan('logs:prune')->assertExitCode(0);

    $archive = LogArchive::query()->sole();

    $this->artisan('logs:restore-archive')
        ->expectsOutputToContain('login_logs')
        ->assertExitCode(0);

    $this->artisan('logs:restore-archive', ['id' => $archive->id])
        ->expectsOutputToContain(__('ops.archive.restored', ['rows' => '1', 'table' => 'login_logs']))
        ->assertExitCode(0);

    expect(DB::table('login_logs')->count())->toBe(1);

    $this->artisan('logs:restore-archive', ['id' => 99999])
        ->expectsOutputToContain(__('ops.archive.not_found', ['id' => '99999']))
        ->assertExitCode(1);
});

it('refuses to restore an archive whose file has gone (F-4.6-008)', function (): void {
    config(['emp-archive.chunk' => 100]);

    archivableLoginLog('old@x.test', Carbon::now()->subDays(400));

    $this->artisan('logs:prune')->assertExitCode(0);

    $archive = LogArchive::query()->sole();
    Storage::disk('archive')->delete($archive->path);

    expect(fn () => app(LogArchiver::class)->restore($archive))
        ->toThrow(RuntimeException::class);

    expect(DB::table('login_logs')->count())->toBe(0);
});
