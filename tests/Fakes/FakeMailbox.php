<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Mailboxes\MailboxClientInterface;
use App\Services\Mailboxes\MailboxConnection;
use RuntimeException;

/**
 * In-memory mailbox for bounce/FBL processing tests (WP-15). Bind it on
 * MailboxClientInterface — MailboxClientFactory returns the bound instance
 * for every protocol.
 */
final class FakeMailbox implements MailboxClientInterface
{
    /** @var list<int|string> */
    public array $deleted = [];

    public bool $connected = false;

    public ?MailboxConnection $lastConnection = null;

    public bool $failConnect = false;

    public int $disconnects = 0;

    /** @param array<int|string, string> $messages id => raw source */
    public function __construct(public array $messages = []) {}

    public function connect(MailboxConnection $connection): void
    {
        if ($this->failConnect) {
            throw new RuntimeException('FakeMailbox: connection refused.');
        }

        $this->connected = true;
        $this->lastConnection = $connection;
    }

    public function listMessages(int $limit): array
    {
        $ids = array_keys($this->messages);

        return $limit > 0 ? array_slice($ids, 0, $limit) : $ids;
    }

    public function fetch(int|string $id): string
    {
        return $this->messages[$id] ?? throw new RuntimeException("FakeMailbox: unknown message [{$id}].");
    }

    public function delete(int|string $id): void
    {
        $this->deleted[] = $id;
    }

    public function disconnect(): void
    {
        $this->connected = false;
        $this->disconnects++;
    }
}
