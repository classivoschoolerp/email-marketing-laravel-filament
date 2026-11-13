<?php

declare(strict_types=1);

namespace App\Services\SpamCheck;

/**
 * No-op spam checker (D-08): used when SpamAssassin is disabled/absent.
 * Every message passes with a zero score.
 */
class NullChecker implements SpamCheckerInterface
{
    public function check(string $rawMessage): SpamCheckResult
    {
        return SpamCheckResult::clean();
    }
}
