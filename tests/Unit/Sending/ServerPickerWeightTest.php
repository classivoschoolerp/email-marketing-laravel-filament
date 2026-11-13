<?php

declare(strict_types=1);

use App\Models\DeliveryServer;
use App\Sending\ServerPicker;
use App\Sending\ServerUsage;
use App\Services\Sending\DeliveryServerQuota;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * F-2.9.2-022/028 — weighted-random selection, deterministic via a seeded
 * Randomizer. DB-backed eligibility filtering lives in the feature suite.
 */

function weightPicker(int $seed): ServerPicker
{
    return new ServerPicker(
        app(ServerUsage::class),
        app(DeliveryServerQuota::class),
        new Randomizer(new Mt19937($seed)),
    );
}

function weightedServers(array $weights): array
{
    return array_map(
        fn (int $weight, int $i) => new DeliveryServer([
            'type' => 'smtp',
            'name' => 'server-'.$i,
            'probability' => $weight,
        ]),
        $weights,
        array_keys($weights),
    );
}

it('returns null for an empty pool and the sole server for a pool of one', function (): void {
    $picker = weightPicker(1);
    $only = weightedServers([50])[0];

    expect($picker->weightedChoice([]))->toBeNull()
        ->and($picker->weightedChoice([$only]))->toBe($only);
});

it('is deterministic for a fixed seed', function (): void {
    $servers = weightedServers([70, 20, 10]);

    $first = array_map(
        fn () => weightPicker(1234)->weightedChoice($servers)->name,
        range(1, 10),
    );
    $second = array_map(
        fn () => weightPicker(1234)->weightedChoice($servers)->name,
        range(1, 10),
    );

    expect($first)->toBe($second);
});

// Distribution smoke test: observed shares track the probability weights
it('distributes picks according to the probability weights', function (): void {
    $servers = weightedServers([80, 20]);
    $picker = weightPicker(42);

    $counts = ['server-0' => 0, 'server-1' => 0];

    for ($i = 0; $i < 10_000; $i++) {
        $counts[$picker->weightedChoice($servers)->name]++;
    }

    // 80/20 split within a generous ±5-point tolerance.
    expect($counts['server-0'] / 10_000)->toBeGreaterThan(0.75)->toBeLessThan(0.85)
        ->and($counts['server-1'] / 10_000)->toBeGreaterThan(0.15)->toBeLessThan(0.25);
});

it('never picks a zero-weight server while positive weights exist', function (): void {
    $servers = weightedServers([0, 100]);
    $picker = weightPicker(7);

    for ($i = 0; $i < 1_000; $i++) {
        expect($picker->weightedChoice($servers)->name)->toBe('server-1');
    }
});

it('falls back to a uniform choice when every weight is zero', function (): void {
    $servers = weightedServers([0, 0]);
    $picker = weightPicker(99);

    $picked = [];

    for ($i = 0; $i < 200; $i++) {
        $picked[$picker->weightedChoice($servers)->name] = true;
    }

    expect($picked)->toHaveKeys(['server-0', 'server-1']);
});
