<?php

declare(strict_types=1);

namespace App\Services\Monitors;

/**
 * The three fields a monitor condition can match (spec §2.9.6 F-2.9.6-002),
 * extracted from a raw mailbox message by MonitorMessageReader.
 */
final class MonitorMessage
{
    public function __construct(
        public readonly string $subject = '',
        public readonly string $body = '',
        public readonly string $from = '',
        /** Bare address parsed out of the From header, lower-cased. */
        public readonly ?string $fromEmail = null,
    ) {}
}
