<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * Outcome of one file scan (spec §3.25.15 F-3.25.15-006).
 *
 * `scanned = false` means no verdict was reached (scanner disabled or
 * unreachable); such a result is never infected and never blocks.
 */
final readonly class ScanResult
{
    public function __construct(
        public bool $scanned,
        public bool $infected = false,
        public ?string $signature = null,
        public ?string $error = null,
    ) {}

    public static function clean(): self
    {
        return new self(scanned: true);
    }

    public static function infected(string $signature): self
    {
        return new self(scanned: true, infected: true, signature: $signature);
    }

    /** No verdict: scanner off, or the daemon could not be reached. */
    public static function skipped(?string $error = null): self
    {
        return new self(scanned: false, error: $error);
    }
}
