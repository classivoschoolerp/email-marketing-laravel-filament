<?php

declare(strict_types=1);

use App\Console\Commands\CronHeartbeat;
use App\Filament\Admin\Pages\Settings\CronRunHistoryPage;
use App\Models\CronRun;
use App\Models\Customer;
use App\Models\User;
use App\Services\Cron\CronRunRecorder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// F-3.25.11-003 — start/finish
it('records a successful cron run with duration and processed count', function (): void {
    $recorder = app(CronRunRecorder::class);

    $run = $recorder->start('campaigns:send');

    expect($run->status)->toBe(CronRun::STATUS_RUNNING)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->toBeNull();

    $recorder->finish($run, processed: 123);

    $run->refresh();

    expect($run->status)->toBe(CronRun::STATUS_SUCCESS)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($run->processed)->toBe(123)
        ->and($run->error)->toBeNull();
});

// F-3.25.11-003 — errors
it('records a failed cron run with the error message', function (): void {
    $recorder = app(CronRunRecorder::class);

    $run = $recorder->start('bounces:process');
    $recorder->fail($run, new RuntimeException('mailbox unreachable'));

    $run->refresh();

    expect($run->status)->toBe(CronRun::STATUS_FAILED)
        ->and($run->error)->toContain('RuntimeException')
        ->and($run->error)->toContain('mailbox unreachable');
});

it('wraps a job body with record() and returns its result', function (): void {
    $result = app(CronRunRecorder::class)->record('stats:rollup', fn (): int => 42);

    expect($result)->toBe(42);

    $run = CronRun::query()->where('job', 'stats:rollup')->firstOrFail();

    expect($run->status)->toBe(CronRun::STATUS_SUCCESS)
        ->and($run->processed)->toBe(42);
});

it('marks the run failed and rethrows when the wrapped job throws', function (): void {
    expect(fn () => app(CronRunRecorder::class)->record('logs:prune', function (): void {
        throw new RuntimeException('disk full');
    }))->toThrow(RuntimeException::class, 'disk full');

    $run = CronRun::query()->where('job', 'logs:prune')->firstOrFail();

    expect($run->status)->toBe(CronRun::STATUS_FAILED)
        ->and($run->error)->toContain('disk full');
});

// §0.6 heartbeat — system:cron-heartbeat
it('writes a heartbeat run and cache timestamp from the artisan command', function (): void {
    Cache::forget(CronHeartbeat::CACHE_KEY);

    $this->artisan('system:cron-heartbeat')->assertSuccessful();

    $run = CronRun::query()->where('job', CronHeartbeat::JOB_NAME)->firstOrFail();

    expect($run->status)->toBe(CronRun::STATUS_SUCCESS)
        ->and(Cache::get(CronHeartbeat::CACHE_KEY))->not->toBeNull();
});

// F-3.25.11-003 — read-only history page
it('renders the cron run history for an admin', function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $recorder = app(CronRunRecorder::class);
    $recorder->finish($recorder->start('campaigns:send'), processed: 10);
    $recorder->fail($recorder->start('fbl:process'), 'imap timeout');

    $this->get(CronRunHistoryPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.cron_history.title'));

    Livewire::test(CronRunHistoryPage::class)
        ->assertCanSeeTableRecords(CronRun::query()->get())
        ->assertSee('campaigns:send')
        ->assertSee('fbl:process')
        ->assertSee('imap timeout');
});

it('denies the customer guard access to the cron run history', function (): void {
    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get(CronRunHistoryPage::getUrl(panel: 'admin'))->assertRedirect();
});
