<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

/**
 * Postfix local mailer (spec §2.9.2 F-2.9.2-019, D-21 key "postfix").
 * Postfix installs a sendmail-compatible wrapper at /usr/sbin/sendmail
 * (postfix-sendmail); -bs is fully supported. Classification table: see
 * AbstractSendmailDriver.
 */
class PostfixDriver extends AbstractSendmailDriver
{
    public function key(): string
    {
        return 'postfix';
    }

    protected function defaultBinaryPath(): string
    {
        return '/usr/sbin/sendmail';
    }
}
