<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use RuntimeException;

/**
 * Anything that aborts an upgrade (spec §6.2, WP-41).
 *
 * Carries the step it failed on so {@see UpgradeRunner} can record the exact
 * point of failure on the upgrade_runs row — which is what makes the recovery
 * path in docs/upgrades.md actionable rather than guesswork.
 */
class UpgradeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $step = null,
        /** @var list<string> */
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /** @param list<string> $errors */
    public static function atStep(string $step, string $message, array $errors = []): self
    {
        return new self($message, $step, $errors);
    }
}
