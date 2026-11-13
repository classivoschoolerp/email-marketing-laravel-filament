<?php

declare(strict_types=1);

use App\Support\Stats\LiveCounter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function counter(): LiveCounter
{
    return new LiveCounter(new Repository(new ArrayStore));
}

it('accumulates and reads counter values', function (): void {
    $c = counter();

    $c->increment('opens:campaign:1');
    $c->increment('opens:campaign:1', 2);

    expect($c->value('opens:campaign:1'))->toBe(3);
});

it('drains all counters atomically and resets them', function (): void {
    $c = counter();
    $c->increment('opens:1', 2);
    $c->increment('clicks:1', 5);

    expect($c->drain())->toBe(['opens:1' => 2, 'clicks:1' => 5])
        ->and($c->value('opens:1'))->toBe(0)
        ->and($c->drain())->toBe([]);
});
