<?php

declare(strict_types=1);

namespace App\Sending;

use App\Services\Settings\SettingsReader;
use Throwable;

/**
 * Per-recipient automatic retry policy (spec §2.9.2 F-2.9.2-023).
 *
 * Transient failures are retried up to max_attempts total attempts with
 * exponential backoff (backoff_seconds * 2^(attempt-1), capped at one day);
 * permanent failures never retry. Configured from CampaignSettings §3.25.3
 * (retry_max_attempts / retry_backoff_seconds) with config fallback.
 *
 * Not readonly: instances may be captured by queued jobs (D-46).
 */
final class RetryPolicy
{
    public const MAX_BACKOFF_SECONDS = 86_400;

    public function __construct(
        public int $maxAttempts,
        public int $backoffSeconds,
    ) {}

    public static function fromSettings(): self
    {
        try {
            $campaign = SettingsReader::campaign();

            return new self(
                max(1, $campaign->retryMaxAttempts()),
                max(0, $campaign->retryBackoffSeconds()),
            );
        } catch (Throwable) {
            // Pre-migration / settings table unavailable: config fallback.
            return new self(
                max(1, (int) config('emp-sending.retry.max_attempts', 3)),
                max(0, (int) config('emp-sending.retry.backoff_seconds', 300)),
            );
        }
    }

    /**
     * Retry after this outcome? $attempt is the 1-based number of the attempt
     * that just finished: transient failures retry while attempts remain,
     * permanent failures and successes never do.
     */
    public function shouldRetry(SendResult $result, int $attempt): bool
    {
        return $result->failed()
            && $result->transient
            && $attempt < $this->maxAttempts;
    }

    /** Seconds to wait before the attempt AFTER $attempt (exponential). */
    public function delayForAttempt(int $attempt): int
    {
        $attempt = max(1, $attempt);

        return (int) min(
            self::MAX_BACKOFF_SECONDS,
            $this->backoffSeconds * (2 ** ($attempt - 1)),
        );
    }
}
