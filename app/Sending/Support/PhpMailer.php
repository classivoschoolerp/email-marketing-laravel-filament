<?php

declare(strict_types=1);

namespace App\Sending\Support;

/**
 * Seam around PHP's native mail() so the php-mail driver is testable with a
 * spy (spec §2.9.2 F-2.9.2-014; docs/DECISIONS.md D-16 — no live sends in tests).
 */
interface PhpMailer
{
    /**
     * Same contract as mail(): $to and $subject already RFC 2047 encoded,
     * $headers is the raw additional-headers block, $params the optional
     * additional sendmail params (e.g. "-fbounce@example.com").
     */
    public function mail(string $to, string $subject, string $body, string $headers, string $params = ''): bool;
}
