<?php

declare(strict_types=1);

use App\Services\Ops\WorkerRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// §6.9 F-6.9-007 — health-check endpoint for load balancers.

it('returns 200 with a per-dependency breakdown when everything is up', function (): void {
    $response = $this->getJson('/health')->assertOk();

    expect($response->json('status'))->toBe('ok')
        ->and($response->json('checks.database.ok'))->toBeTrue()
        ->and($response->json('checks.database.required'))->toBeTrue()
        ->and($response->json('checks.cache.ok'))->toBeTrue()
        ->and($response->json('checks.cache.required'))->toBeTrue()
        // Queue/worker health must never pull a healthy web node out of rotation.
        ->and($response->json('checks.queue.required'))->toBeFalse()
        ->and($response->json('checks.workers.required'))->toBeFalse()
        ->and($response->json('checks.workers.alive'))->toBe(0)
        ->and($response->json('timestamp'))->not->toBeNull();
});

it('counts live workers and reports queue depth on demand', function (): void {
    app(WorkerRegistry::class)->heartbeat('campaigns-send', 'database');

    config()->set('queue.default', 'database');

    DB::table('jobs')->insert([
        ['queue' => 'exports', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()],
    ]);

    $response = $this->getJson('/health?detailed=1')->assertOk();

    expect($response->json('checks.workers.alive'))->toBe(1)
        ->and($response->json('checks.queue.pending'))->toBe(1);
});

it('reports an uninspectable queue driver honestly instead of failing', function (): void {
    config()->set('queue.default', 'sync');

    $response = $this->getJson('/health')->assertOk();

    expect($response->json('status'))->toBe('ok')
        ->and($response->json('checks.queue.ok'))->toBeTrue()
        ->and($response->json('checks.queue.message'))->toContain('sync');
});

it('returns 503 when a required dependency is down', function (): void {
    // A misconfigured cache store is the safest way to break a REQUIRED
    // dependency without tearing down the connection the test harness uses.
    config()->set('cache.default', 'not-a-configured-store');
    Cache::purge('not-a-configured-store');

    $response = $this->getJson('/health')->assertStatus(503);

    expect($response->json('status'))->toBe('error')
        ->and($response->json('checks.cache.ok'))->toBeFalse()
        ->and($response->json('checks.database.ok'))->toBeTrue()
        // An unauthenticated endpoint leaks no paths, credentials or traces.
        ->and($response->json('checks.cache.message'))->not->toContain(base_path())
        ->and($response->json('checks.cache.message'))->not->toContain('#0 ');
});

it('keeps the cheap /up liveness probe alongside /health', function (): void {
    $this->get('/up')->assertOk();
});
