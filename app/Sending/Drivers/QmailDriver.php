<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

/**
 * Qmail local mailer (spec §2.9.2 F-2.9.2-019, D-21 key "qmail").
 * qmail-inject has no SMTP stdio mode — it always reads the message from
 * stdin and takes recipients from the headers, so the default mode is "-t"
 * (pipe), not "-bs". Classification table: see AbstractSendmailDriver.
 */
class QmailDriver extends AbstractSendmailDriver
{
    public function key(): string
    {
        return 'qmail';
    }

    protected function defaultBinaryPath(): string
    {
        return '/var/qmail/bin/qmail-inject';
    }

    protected function defaultMode(): string
    {
        return '-t';
    }
}
