<?php

declare(strict_types=1);

namespace App\Services\Mailboxes;

use App\Enums\MailboxProtocol;

/**
 * Builds the mailbox client for a server's protocol (spec §2.9.4). Test seam
 * WITHOUT a service-provider binding: when the container has an explicit
 * MailboxClientInterface binding (tests bind FakeMailbox), that instance is
 * returned for every protocol; otherwise the real client is constructed.
 */
class MailboxClientFactory
{
    public function make(MailboxProtocol $protocol): MailboxClientInterface
    {
        if (app()->bound(MailboxClientInterface::class)) {
            return app(MailboxClientInterface::class);
        }

        return match ($protocol) {
            MailboxProtocol::Pop3 => new Pop3Client,
            MailboxProtocol::Imap => new ImapClient,
        };
    }
}
