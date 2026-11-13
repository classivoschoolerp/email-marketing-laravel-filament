<?php

declare(strict_types=1);

namespace App\Services\Mailboxes;

use App\Enums\MailboxEncryption;
use IMAP\Connection;
use RuntimeException;

/**
 * IMAP mailbox client (spec §2.9.4 F-2.9.4-001) built on ext-imap.
 *
 * HONEST LIMITATION: this client REQUIRES the PHP IMAP extension. When
 * ext-imap is not loaded (the common case on stock Windows/PHP 8.3 builds —
 * the extension was unbundled to PECL in PHP 8.4 anyway), connect() throws a
 * clear RuntimeException instead of pretending to work. Operators either
 * install ext-imap or configure their bounce/FBL mailboxes as POP3, which is
 * fully supported by the dependency-free Pop3Client. The `search_charset`
 * column applies to IMAP SEARCH only; POP3 has no server-side search.
 */
final class ImapClient implements MailboxClientInterface
{
    /** @var resource|Connection|null */
    private mixed $stream = null;

    public function connect(MailboxConnection $connection): void
    {
        if (! extension_loaded('imap')) {
            throw new RuntimeException(
                'The IMAP mailbox client requires ext-imap, which is not installed on this server. '
                .'Install the PHP IMAP extension or switch this server to the POP3 protocol.',
            );
        }

        $flags = '/imap';

        $flags .= match ($connection->encryption) {
            MailboxEncryption::Ssl => '/ssl',
            MailboxEncryption::Tls => '/tls',
            MailboxEncryption::None => '/notls',
        };

        if (! $connection->validateCert) {
            $flags .= '/novalidate-cert';
        }

        // Folder defaults to INBOX (WP-15 behaviour); WP-46's placement poller
        // is the only caller that ever asks for another one.
        $mailbox = sprintf(
            '{%s:%d%s}%s',
            $connection->hostname,
            $connection->port,
            $flags,
            $connection->folder === '' ? 'INBOX' : $connection->folder,
        );

        $stream = @imap_open($mailbox, $connection->username, $connection->password, 0, 1);

        if ($stream === false) {
            throw new RuntimeException('IMAP connection failed: '.(imap_last_error() ?: 'unknown error'));
        }

        $this->stream = $stream;
    }

    public function listMessages(int $limit): array
    {
        $count = imap_num_msg($this->assertConnected());

        if ($count === false) {
            return [];
        }

        $count = $limit > 0 ? min((int) $count, $limit) : (int) $count;

        return $count > 0 ? range(1, $count) : [];
    }

    public function fetch(int|string $id): string
    {
        $stream = $this->assertConnected();

        $headers = imap_fetchheader($stream, (int) $id);
        $body = imap_body($stream, (int) $id, FT_PEEK);

        return ($headers === false ? '' : $headers).($body === false ? '' : $body);
    }

    public function delete(int|string $id): void
    {
        imap_delete($this->assertConnected(), (string) $id);
    }

    public function disconnect(): void
    {
        if ($this->stream !== null) {
            imap_expunge($this->stream);
            imap_close($this->stream);
            $this->stream = null;
        }
    }

    /** @return resource|Connection */
    private function assertConnected(): mixed
    {
        if ($this->stream === null) {
            throw new RuntimeException('IMAP client is not connected.');
        }

        return $this->stream;
    }
}
