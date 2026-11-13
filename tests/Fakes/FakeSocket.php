<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Mailboxes\SocketInterface;
use RuntimeException;

/**
 * Scripted transcript socket for Pop3Client unit tests (WP-15): responses
 * are served in order from the queue, every written command is recorded.
 */
final class FakeSocket implements SocketInterface
{
    /** @var list<string> */
    public array $written = [];

    /** @var array{hostname: string, port: int, tls: bool, validateCert: bool}|null */
    public ?array $opened = null;

    public bool $cryptoEnabled = false;

    public bool $closed = false;

    /** @param list<string> $responses */
    public function __construct(private array $responses) {}

    public function open(string $hostname, int $port, bool $tls, bool $validateCert, int $timeout): void
    {
        $this->opened = [
            'hostname' => $hostname,
            'port' => $port,
            'tls' => $tls,
            'validateCert' => $validateCert,
        ];
    }

    public function readLine(): string
    {
        if ($this->responses === []) {
            throw new RuntimeException('FakeSocket transcript exhausted.');
        }

        return array_shift($this->responses);
    }

    public function write(string $data): void
    {
        $this->written[] = rtrim($data, "\r\n");
    }

    public function enableCrypto(bool $validateCert): void
    {
        $this->cryptoEnabled = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
