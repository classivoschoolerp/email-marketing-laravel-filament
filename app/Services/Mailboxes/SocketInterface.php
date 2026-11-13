<?php

declare(strict_types=1);

namespace App\Services\Mailboxes;

use RuntimeException;

/**
 * Thin transport seam under Pop3Client so the POP3 protocol logic can be
 * unit-tested against a scripted transcript (tests/Fakes/FakeSocket) without
 * network access.
 */
interface SocketInterface
{
    /** @throws RuntimeException when the TCP/TLS connection fails */
    public function open(string $hostname, int $port, bool $tls, bool $validateCert, int $timeout): void;

    /** One CRLF-terminated line, without the trailing CRLF. */
    public function readLine(): string;

    /** Writes raw bytes (caller includes the CRLF). */
    public function write(string $data): void;

    /** Upgrade a plaintext connection to TLS (POP3 STLS). */
    public function enableCrypto(bool $validateCert): void;

    public function close(): void;
}
