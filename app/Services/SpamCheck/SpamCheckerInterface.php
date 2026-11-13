<?php

declare(strict_types=1);

namespace App\Services\SpamCheck;

/**
 * Pre-send spam gate (spec §2.9.2 F-2.9.2-024 / §2.3.5, D-08). The send path
 * checks the fully-built MIME message before handing it to a driver and must
 * refuse delivery when the result says blocked.
 */
interface SpamCheckerInterface
{
    /** @param  string  $rawMessage  full RFC 2822 message (headers + body) */
    public function check(string $rawMessage): SpamCheckResult;
}
