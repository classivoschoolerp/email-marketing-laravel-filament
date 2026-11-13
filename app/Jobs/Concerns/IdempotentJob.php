<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\Idempotent;
use Illuminate\Support\Facades\DB;

/**
 * Jobs using this trait must implement idempotencyKey(): string returning a stable,
 * globally unique key for the unit of work (e.g. "send:{campaign_uid}:{subscriber_uid}").
 */
trait IdempotentJob
{
    abstract public function idempotencyKey(): string;

    public function middleware(): array
    {
        return [new Idempotent];
    }

    /**
     * Explicitly give the key back when the job decides NOT to perform its
     * work (e.g. a send job observing a paused campaign, WP-13) so a later
     * re-dispatch of the same unit is not silently dropped.
     */
    public function releaseIdempotencyKey(): void
    {
        DB::table('job_idempotency_keys')->where('key', $this->idempotencyKey())->delete();
    }
}
