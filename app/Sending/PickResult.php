<?php

declare(strict_types=1);

namespace App\Sending;

use App\Models\DeliveryServer;

/**
 * Outcome of ServerPicker::pickFor() (spec §2.9.2 F-2.9.2-022/030).
 * Not readonly: may travel through queued jobs (docs/DECISIONS.md D-46).
 */
final class PickResult
{
    public function __construct(
        public ?DeliveryServer $server,
        /** True when a quota-exhausted PAUSE-mode server demands waiting. */
        public bool $shouldPause = false,
        /** Suggested wait (seconds) until the blocking window resets. */
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function none(): self
    {
        return new self(null);
    }

    public static function pause(int $retryAfterSeconds): self
    {
        return new self(null, shouldPause: true, retryAfterSeconds: $retryAfterSeconds);
    }

    public function picked(): bool
    {
        return $this->server !== null;
    }
}
