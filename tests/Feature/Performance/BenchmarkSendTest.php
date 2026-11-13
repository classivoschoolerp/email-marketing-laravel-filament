<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Subscriber;
use Illuminate\Support\Facades\Mail;

/*
 * WP-26 §4.6 F-4.6-009 — the throughput benchmark command runs at small N,
 * drives the real pipeline and reports honest numbers. The 1M/hour claim
 * itself needs real infrastructure (D-14) and stays on the e2e checklist;
 * nothing here fakes it.
 */

beforeEach(function (): void {
    Mail::fake();
});

it('runs the real send pipeline and reports machine-readable numbers', function (): void {
    $exit = \Illuminate\Support\Facades\Artisan::call(
        'emp:benchmark-send', ['--subscribers' => 25, '--batch-size' => 10, '--json' => true],
    );

    expect($exit)->toBe(0);

    $results = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

    expect($results)->toBeArray()
        ->and($results['subscribers_seeded'])->toBe(25)
        // Every seeded recipient went through the pipeline to the null sink.
        ->and($results['messages_sent'])->toBe(25)
        ->and($results['delivery_log_rows'])->toBe(25)
        ->and($results['messages_per_minute'])->toBeGreaterThan(0)
        ->and($results['messages_per_hour'])->toBeGreaterThan(0)
        ->and($results['queue_connection'])->toBe('sync')
        ->and($results['db_driver'])->toBe('sqlite')
        ->and($results['bottleneck'])->toBeString()
        ->and($results['bottleneck'])->not->toBe('');
});

it('labels the extrapolated cluster rate as arithmetic, never as a measurement', function (): void {
    \Illuminate\Support\Facades\Artisan::call(
        'emp:benchmark-send', ['--subscribers' => 5, '--workers' => 8, '--json' => true],
    );

    $results = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

    expect($results['workers_assumed'])->toBe(8)
        ->and($results['extrapolated_per_hour'])->toBe($results['messages_per_hour'] * 8);

    // The human-readable run states the caveat out loud.
    $this->artisan('emp:benchmark-send --subscribers=5')
        ->expectsOutputToContain('NOT a verified cluster figure')
        ->assertSuccessful();
});

it('cleans up everything it seeded by default and keeps it with --keep', function (): void {
    $customersBefore = Customer::query()->count();

    \Illuminate\Support\Facades\Artisan::call('emp:benchmark-send', ['--subscribers' => 5, '--json' => true]);

    expect(Customer::query()->count())->toBe($customersBefore)
        ->and(Subscriber::withoutTenancy()->count())->toBe(0)
        ->and(Campaign::withoutTenancy()->count())->toBe(0);

    \Illuminate\Support\Facades\Artisan::call('emp:benchmark-send', ['--subscribers' => 5, '--keep' => true, '--json' => true]);

    expect(Customer::query()->count())->toBe($customersBefore + 1)
        ->and(Subscriber::withoutTenancy()->count())->toBe(5);
});
