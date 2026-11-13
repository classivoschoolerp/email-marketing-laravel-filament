<?php

declare(strict_types=1);

namespace App\Services\SpamCheck;

/**
 * Outcome of a pre-send spam check (spec §2.9.2 F-2.9.2-024, D-08):
 * spamd score + rule breakdown + the block decision against the platform
 * threshold. Not readonly: may be captured by queued jobs (D-46).
 */
final class SpamCheckResult
{
    /**
     * @param  list<array{points: float, rule: string, description: string}>  $rules
     */
    public function __construct(
        /** False when the daemon was unreachable (check() fails open). */
        public bool $checked,
        public float $score = 0.0,
        /** spamd's own required_score from the Spam: header. */
        public float $requiredScore = 5.0,
        /** spamd's verdict (Spam: True/False). */
        public bool $isSpam = false,
        public array $rules = [],
        /** Platform decision: block the send (score >= platform threshold)? */
        public bool $blocked = false,
        public ?string $error = null,
        public ?string $report = null,
    ) {}

    public static function unavailable(string $error): self
    {
        return new self(checked: false, error: $error);
    }

    public static function clean(): self
    {
        return new self(checked: true);
    }

    public function passed(): bool
    {
        return ! $this->blocked;
    }
}
