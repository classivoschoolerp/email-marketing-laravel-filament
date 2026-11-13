<?php

declare(strict_types=1);

use App\Filament\Admin\Widgets\SystemErrorsWidget;
use App\Services\Ops\ErrorLogWatcher;
use App\Settings\OpsSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

// §6.6 F-6.6-002 — system error notifications surfaced INSIDE the admin area
// (not just log files): poll-based scan of the log tail → Filament database
// notifications for every admin who may read logs, plus the errors widget.

function wp24SeedLog(array $lines): string
{
    $path = storage_path('framework/testing/wp24-ops-'.uniqid().'.log');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

it('finds only entries at or above the configured severity', function (): void {
    $path = wp24SeedLog([
        '[2026-08-08 10:00:00] testing.INFO: routine',
        '[2026-08-08 10:01:00] testing.WARNING: careful',
        '[2026-08-08 10:02:00] testing.ERROR: exploded',
        '[2026-08-08 10:03:00] testing.CRITICAL: very bad',
    ]);

    $watcher = app(ErrorLogWatcher::class);

    expect($watcher->minimumLevel())->toBe('ERROR')
        ->and($watcher->levels())->toBe(['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY']);

    $entries = $watcher->recent(10, $path);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['level'])->toBe('CRITICAL')
        ->and($entries[1]['message'])->toBe('exploded');

    $settings = app(OpsSettings::class);
    $settings->error_notification_level = 'CRITICAL';
    $settings->save();

    expect(app(ErrorLogWatcher::class)->recent(10, $path))->toHaveCount(1);

    @unlink($path);
});

it('notifies admins about new errors and never announces the same entry twice', function (): void {
    $admin = AdminTestHelpers::admin(['logs.view']);
    $other = AdminTestHelpers::admin();          // no logs.view → not notified

    $path = wp24SeedLog([
        '[2026-08-08 10:00:00] testing.INFO: routine',
        '[2026-08-08 10:02:00] testing.ERROR: first failure',
    ]);

    $watcher = app(ErrorLogWatcher::class);

    expect($watcher->scan($path))->toBe(1)
        ->and(DB::table('notifications')->where('notifiable_id', $admin->id)->count())->toBe(1)
        ->and(DB::table('notifications')->where('notifiable_id', $other->id)->count())->toBe(0);

    $payload = (string) DB::table('notifications')->where('notifiable_id', $admin->id)->value('data');

    expect($payload)->toContain('first failure')
        ->and($payload)->toContain(__('ops.errors.notification_title', ['level' => 'ERROR']));

    // Re-scanning the unchanged file announces nothing.
    expect($watcher->scan($path))->toBe(0)
        ->and(DB::table('notifications')->count())->toBe(1);

    // A new entry is picked up; the older one is not re-announced.
    file_put_contents($path, "[2026-08-08 10:05:00] testing.CRITICAL: second failure\n", FILE_APPEND);

    expect($watcher->scan($path))->toBe(1)
        ->and(DB::table('notifications')->count())->toBe(2);

    @unlink($path);
});

it('honours the error-notification off switch', function (): void {
    AdminTestHelpers::admin(['logs.view']);

    $settings = app(OpsSettings::class);
    $settings->error_notifications_enabled = false;
    $settings->save();

    $path = wp24SeedLog(['[2026-08-08 10:02:00] testing.ERROR: ignored']);

    expect(app(ErrorLogWatcher::class)->scan($path))->toBe(0)
        ->and(DB::table('notifications')->count())->toBe(0);

    @unlink($path);
});

it('renders the errors widget with the pending count', function (): void {
    AdminTestHelpers::actingAdmin(['logs.view']);

    $marker = 'wp24-widget-'.uniqid();

    file_put_contents(
        storage_path('logs/laravel.log'),
        "\n[2026-08-08 11:00:00] testing.ERROR: {$marker}\n",
        FILE_APPEND,
    );

    Cache::forget(ErrorLogWatcher::CACHE_KEY);

    Livewire::test(SystemErrorsWidget::class)
        ->assertSee($marker)
        ->assertSee('ERROR');
});

it('runs the ops:monitor sweep end to end', function (): void {
    AdminTestHelpers::admin(['logs.view']);

    $this->artisan('ops:monitor --skip-thresholds')
        ->expectsOutputToContain(__('ops.performance.pruned', ['count' => 0]))
        ->expectsOutputToContain(__('ops.workers.pruned', ['count' => 0]))
        ->assertSuccessful();
});
