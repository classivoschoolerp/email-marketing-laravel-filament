<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Enums\VerificationStatus;

/**
 * Outcome of verifying one address (spec §2.9.12). `reason` is the
 * provider's own sub-status ("mailbox_not_found", "disposable", …) kept for
 * the result CSV; `raw` is the untouched provider payload for debugging.
 */
final class VerificationResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly VerificationStatus $status,
        public readonly ?string $reason = null,
        public readonly array $raw = [],
    ) {}

    public static function deliverable(?string $reason = null, array $raw = []): self
    {
        return new self(VerificationStatus::Deliverable, $reason, $raw);
    }

    public static function undeliverable(?string $reason = null, array $raw = []): self
    {
        return new self(VerificationStatus::Undeliverable, $reason, $raw);
    }

    public static function risky(?string $reason = null, array $raw = []): self
    {
        return new self(VerificationStatus::Risky, $reason, $raw);
    }

    public static function unknown(?string $reason = null, array $raw = []): self
    {
        return new self(VerificationStatus::Unknown, $reason, $raw);
    }
}
