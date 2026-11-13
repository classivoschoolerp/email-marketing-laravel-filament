<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Guarantees at-most-once execution for jobs exposing idempotencyKey() (docs/ARCHITECTURE.md rule 6).
 * The unique index on job_idempotency_keys.key is the arbiter, so the guarantee holds
 * across parallel workers and across job retries after a mid-batch worker death.
 */
final class Idempotent
{
    public function handle(object $job, Closure $next): void
    {
        $key = $job->idempotencyKey();

        try {
            DB::table('job_idempotency_keys')->insert([
                'key' => $key,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already executed (or executing) elsewhere — drop silently.
            return;
        }

        try {
            $next($job);

            // Job deferred itself back onto the queue (rate-limit / send-window
            // deferral, WP-13): the work has NOT run — release the key so the
            // redelivery is not dropped.
            if (method_exists($job, 'job') || property_exists($job, 'job')) {
                $queueJob = $job->job ?? null;

                if ($queueJob !== null && method_exists($queueJob, 'isReleased') && $queueJob->isReleased()) {
                    DB::table('job_idempotency_keys')->where('key', $key)->delete();
                }
            }
        } catch (\Throwable $e) {
            // Job failed before completing: release the key so a retry can run.
            DB::table('job_idempotency_keys')->where('key', $key)->delete();

            throw $e;
        }
    }
}
