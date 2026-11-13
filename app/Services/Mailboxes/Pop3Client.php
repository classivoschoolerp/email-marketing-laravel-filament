<?php

declare(strict_types=1);

namespace App\Services\Mailboxes;

use App\Enums\MailboxEncryption;
use RuntimeException;

/**
 * Raw-socket POP3 client (RFC 1939) for bounce/FBL mailboxes (spec §2.9.4).
 * Speaks the minimal command set the processors need: USER/PASS auth, STAT,
 * RETR (with dot-unstuffing), DELE and QUIT. Encryption: `ssl` = implicit
 * TLS on connect, `tls` = STLS upgrade (RFC 2595), `none` = plaintext.
 *
 * The transport is injected (SocketInterface) so the protocol logic is
 * unit-tested against a scripted FakeSocket transcript — no network needed.
 */
final class Pop3Client implements MailboxClientInterface
{
    private bool $connected = false;

    public function __construct(private readonly SocketInterface $socket = new StreamSocket) {}

    public function connect(MailboxConnection $connection): void
    {
        $this->socket->open(
            $connection->hostname,
            $connection->port,
            $connection->encryption === MailboxEncryption::Ssl,
            $connection->validateCert,
            $connection->timeout,
        );

        $this->connected = true;
        $this->expectOk($this->socket->readLine(), 'greeting');

        if ($connection->encryption === MailboxEncryption::Tls) {
            $this->expectOk($this->command('STLS'), 'STLS');
            $this->socket->enableCrypto($connection->validateCert);
        }

        $this->expectOk($this->command('USER '.$connection->username), 'USER');
        $this->expectOk($this->command('PASS '.$connection->password), 'PASS');
    }

    public function listMessages(int $limit): array
    {
        $response = $this->command('STAT');
        $this->expectOk($response, 'STAT');

        // "+OK <count> <octets>"
        $parts = explode(' ', $response);
        $count = (int) ($parts[1] ?? 0);

        if ($limit > 0) {
            $count = min($count, $limit);
        }

        return $count > 0 ? range(1, $count) : [];
    }

    public function fetch(int|string $id): string
    {
        $this->expectOk($this->command('RETR '.$id), 'RETR');

        $lines = [];

        while (true) {
            $line = $this->socket->readLine();

            if ($line === '.') {
                break;
            }

            // RFC 1939 §3: leading dots are byte-stuffed.
            $lines[] = str_starts_with($line, '..') ? substr($line, 1) : $line;
        }

        return implode("\r\n", $lines);
    }

    public function delete(int|string $id): void
    {
        $this->expectOk($this->command('DELE '.$id), 'DELE');
    }

    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }

        try {
            $this->command('QUIT'); // commits DELEs (RFC 1939 UPDATE state)
        } catch (RuntimeException) {
            // Connection already gone — nothing to commit.
        } finally {
            $this->socket->close();
            $this->connected = false;
        }
    }

    private function command(string $command): string
    {
        $this->socket->write($command."\r\n");

        return $this->socket->readLine();
    }

    private function expectOk(string $response, string $stage): void
    {
        if (! str_starts_with($response, '+OK')) {
            throw new RuntimeException(sprintf('POP3 %s failed: %s', $stage, $response !== '' ? $response : '(empty response)'));
        }
    }
}
