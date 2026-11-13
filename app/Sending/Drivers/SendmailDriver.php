<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

/**
 * Local MTA via the classic sendmail binary (spec §2.9.2 F-2.9.2-016, D-21
 * key "sendmail"). Classification table: see AbstractSendmailDriver.
 */
class SendmailDriver extends AbstractSendmailDriver
{
    public function key(): string
    {
        return 'sendmail';
    }

    protected function defaultBinaryPath(): string
    {
        return '/usr/sbin/sendmail';
    }
}
