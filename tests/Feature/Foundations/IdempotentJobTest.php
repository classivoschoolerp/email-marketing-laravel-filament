<?php

declare(strict_types=1);

use App\Jobs\Concerns\IdempotentJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

class IdempotencyProbeJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use Queueable;

    public static int $executions = 0;

    public static bool $fail = false;

    public function __construct(private readonly string $key)
    {
    }

    public function idempotencyKey(): string
    {
        return 'probe:'.$this->key;
    }

    public function handle(): void
    {
        self::$executions++;

        if (self::$fail) {
            throw new RuntimeException('simulated worker death');
        }
    }
}

beforeEach(function (): void {
    IdempotencyProbeJob::$executions = 0;
    IdempotencyProbeJob::$fail = false;
});

it('executes a job with the same idempotency key only once', function (): void {
    IdempotencyProbeJob::dispatchSync('k1');
    IdempotencyProbeJob::dispatchSync('k1');

    expect(IdempotencyProbeJob::$executions)->toBe(1)
        ->and(DB::table('job_idempotency_keys')->count())->toBe(1);
});

it('allows different keys to run independently', function (): void {
    IdempotencyProbeJob::dispatchSync('k1');
    IdempotencyProbeJob::dispatchSync('k2');

    expect(IdempotencyProbeJob::$executions)->toBe(2);
});

it('releases the key when the job throws so a retry can run', function (): void {
    IdempotencyProbeJob::$fail = true;

    try {
        IdempotencyProbeJob::dispatchSync('k1');
    } catch (RuntimeException) {
    }

    expect(DB::table('job_idempotency_keys')->count())->toBe(0);

    IdempotencyProbeJob::$fail = false;
    IdempotencyProbeJob::dispatchSync('k1');

    expect(IdempotencyProbeJob::$executions)->toBe(2);
});
