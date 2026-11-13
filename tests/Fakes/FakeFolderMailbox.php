<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Mailboxes\MailboxClientInterface;
use App\Services\Mailboxes\MailboxConnection;
use RuntimeException;

/**
 * Folder-aware in-memory mailbox for inbox-placement tests (WP-46).
 *
 * FakeMailbox serves one flat message set; a placement poller needs to prove
 * that a message found in "Spam" is classified differently from the same
 * message in "INBOX", so this fake keys its messages by the folder the
 * connection asked for. A folder that was never registered THROWS on connect,
 * which is exactly how a real IMAP server behaves for a mailbox that does not
 * exist — and the poller has to survive it.
 *
 * @phpstan-type Folder array<int|string, string>
 */
final class FakeFolderMailbox implements MailboxClientInterface
{
    /** @var list<string> folders opened, in order */
    public array $opened = [];

    /** @var list<int|string> */
    public array $deleted = [];

    public int $disconnects = 0;

    private ?string $folder = null;

    /** @param array<string, array<int|string, string>> $folders folder => (id => raw source) */
    public function __construct(public array $folders = []) {}

    public function connect(MailboxConnection $connection): void
    {
        $this->opened[] = $connection->folder;

        if (! array_key_exists($connection->folder, $this->folders)) {
            $this->folder = null;

            throw new RuntimeException("FakeFolderMailbox: no such folder [{$connection->folder}].");
        }

        $this->folder = $connection->folder;
    }

    public function listMessages(int $limit): array
    {
        $ids = array_keys($this->folders[$this->folder] ?? []);

        return $limit > 0 ? array_slice($ids, 0, $limit) : $ids;
    }

    public function fetch(int|string $id): string
    {
        return $this->folders[$this->folder][$id]
            ?? throw new RuntimeException("FakeFolderMailbox: unknown message [{$id}].");
    }

    public function delete(int|string $id): void
    {
        $this->deleted[] = $id;
    }

    public function disconnect(): void
    {
        $this->folder = null;
        $this->disconnects++;
    }
}
