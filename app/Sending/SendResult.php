<?php

declare(strict_types=1);

namespace App\Sending;

/**
 * Honest outcome of a single driver send (WP-14 seam). `transient` drives the
 * per-recipient RetryPolicy: transient failures (throttling, connection
 * drops, 4xx SMTP, HTTP 429/5xx) may be retried; permanent failures
 * (rejected address, auth, bad config) must not be.
 *
 * Not readonly: may be captured by queued jobs (docs/DECISIONS.md D-46).
 */
final class SendResult
{
    public function __construct(
        public bool $ok,
        public ?string $messageId = null,
        public ?string $error = null,
        public bool $transient = false,
    ) {}

    public static function success(?string $messageId = null): self
    {
        return new self(ok: true, messageId: $messageId);
    }

    public static function transientFailure(string $error): self
    {
        return new self(ok: false, error: $error, transient: true);
    }

    public static function permanentFailure(string $error): self
    {
        return new self(ok: false, error: $error, transient: false);
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }
}
