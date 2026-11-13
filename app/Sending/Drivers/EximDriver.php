<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

/**
 * Exim local mailer (spec §2.9.2 F-2.9.2-019, D-21 key "exim"). Talks to the
 * exim binary directly (exim supports the sendmail-compatible -bs/-t flags).
 * Classification table: see AbstractSendmailDriver.
 */
class EximDriver extends AbstractSendmailDriver
{
    public function key(): string
    {
        return 'exim';
    }

    protected function defaultBinaryPath(): string
    {
        return '/usr/sbin/exim';
    }
}
