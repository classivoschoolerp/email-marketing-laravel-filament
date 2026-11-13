<?php

declare(strict_types=1);

use App\Support\RateLimit\HourlyWindowLimiter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function limiter(): HourlyWindowLimiter
{
    return new HourlyWindowLimiter(new Repository(new ArrayStore));
}

it('counts hits within the window', function (): void {
    $limiter = limiter();

    $limiter->hit('server:1');
    $limiter->hit('server:1', 4);

    expect($limiter->used('server:1'))->toBe(5)
        ->and($limiter->remaining('server:1', 10))->toBe(5);
});

it('detects when a quota would be exceeded', function (): void {
    $limiter = limiter();
    $limiter->hit('server:1', 9);

    expect($limiter->wouldExceed('server:1', 10, 1))->toBeFalse()
        ->and($limiter->wouldExceed('server:1', 10, 2))->toBeTrue();
});

it('treats a zero limit as unlimited', function (): void {
    $limiter = limiter();
    $limiter->hit('server:1', 1000);

    expect($limiter->wouldExceed('server:1', 0, 1))->toBeFalse();
});

it('isolates counters per key', function (): void {
    $limiter = limiter();
    $limiter->hit('server:1', 3);

    expect($limiter->used('server:2'))->toBe(0);
});
