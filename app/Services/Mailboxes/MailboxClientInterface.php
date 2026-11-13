<?php

declare(strict_types=1);

namespace App\Services\Mailboxes;

use RuntimeException;

/**
 * Minimal mailbox access contract for bounce/FBL processing (spec §2.9.4/
 * §2.9.5, WP-15): connect, list message ids, fetch raw RFC 2822 source,
 * delete, disconnect. Implementations: Pop3Client (raw socket), ImapClient
 * (ext-imap), and tests' FakeMailbox.
 */
interface MailboxClientInterface
{
    /** @throws RuntimeException on connection/authentication failure */
    public function connect(MailboxConnection $connection): void;

    /**
     * Ids of messages available in the mailbox, oldest first, capped at
     * $limit (spec §2.9.4 max per batch). Ids stay valid until disconnect.
     *
     * @return list<int|string>
     */
    public function listMessages(int $limit): array;

    /** Raw message source (headers + body). */
    public function fetch(int|string $id): string;

    /** Mark a message deleted (applied on disconnect for POP3). */
    public function delete(int|string $id): void;

    public function disconnect(): void;
}
