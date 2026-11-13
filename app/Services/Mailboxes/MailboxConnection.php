<?php

declare(strict_types=1);

namespace App\Services\Mailboxes;

use App\Enums\MailboxEncryption;
use App\Enums\MailboxProtocol;

/**
 * Connection parameters for a POP3/IMAP mailbox (spec §2.9.4 F-2.9.4-001) —
 * the model-free DTO handed to MailboxClientInterface implementations, built
 * by BounceServer/FblServer::connection().
 */
final class MailboxConnection
{
    public function __construct(
        public readonly string $hostname,
        public readonly int $port,
        public readonly MailboxProtocol $protocol,
        public readonly string $username,
        public readonly string $password,
        public readonly MailboxEncryption $encryption = MailboxEncryption::Ssl,
        public readonly bool $validateCert = true,
        public readonly string $charset = 'UTF-8',
        public readonly int $timeout = 30,
        /**
         * IMAP mailbox (folder) to open. Defaults to INBOX, which is the only
         * folder bounce/FBL processing ever wants and the only one POP3 has.
         * WP-46's inbox placement testing (§2.9.9 F-2.9.9-004) needs to look
         * in the spam folder too, which is the only reason this exists.
         */
        public readonly string $folder = 'INBOX',
    ) {}

    /** Same credentials, a different IMAP folder. */
    public function withFolder(string $folder): self
    {
        return new self(
            hostname: $this->hostname,
            port: $this->port,
            protocol: $this->protocol,
            username: $this->username,
            password: $this->password,
            encryption: $this->encryption,
            validateCert: $this->validateCert,
            charset: $this->charset,
            timeout: $this->timeout,
            folder: trim($folder) === '' ? 'INBOX' : trim($folder),
        );
    }
}
