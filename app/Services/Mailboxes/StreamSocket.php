<?php

declare(strict_types=1);

namespace App\Services\Mailboxes;

use RuntimeException;

/**
 * Production SocketInterface backed by PHP stream sockets. `tls` on open()
 * means implicit TLS (pop3s, port 995); enableCrypto() performs the STARTTLS
 * upgrade after a POP3 STLS handshake. Certificate validation honours the
 * server's SSL/TLS-validation toggle (F-2.9.4-001).
 */
final class StreamSocket implements SocketInterface
{
    /** @var resource|null */
    private $stream;

    public function open(string $hostname, int $port, bool $tls, bool $validateCert, int $timeout): void
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $validateCert,
                'verify_peer_name' => $validateCert,
                'allow_self_signed' => ! $validateCert,
            ],
        ]);

        $remote = ($tls ? 'ssl://' : 'tcp://').$hostname.':'.$port;

        $stream = @stream_socket_client(
            $remote,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            throw new RuntimeException(sprintf(
                'Could not connect to %s: [%d] %s',
                $remote,
                $errorCode,
                $errorMessage !== '' ? $errorMessage : 'connection failed',
            ));
        }

        stream_set_timeout($stream, $timeout);
        $this->stream = $stream;
    }

    public function readLine(): string
    {
        if ($this->stream === null) {
            throw new RuntimeException('Socket is not connected.');
        }

        $line = fgets($this->stream, 65536);

        if ($line === false) {
            throw new RuntimeException('The mailbox server closed the connection unexpectedly.');
        }

        return rtrim($line, "\r\n");
    }

    public function write(string $data): void
    {
        if ($this->stream === null) {
            throw new RuntimeException('Socket is not connected.');
        }

        if (@fwrite($this->stream, $data) === false) {
            throw new RuntimeException('Failed writing to the mailbox server.');
        }
    }

    public function enableCrypto(bool $validateCert): void
    {
        if ($this->stream === null) {
            throw new RuntimeException('Socket is not connected.');
        }

        stream_context_set_option($this->stream, 'ssl', 'verify_peer', $validateCert);
        stream_context_set_option($this->stream, 'ssl', 'verify_peer_name', $validateCert);
        stream_context_set_option($this->stream, 'ssl', 'allow_self_signed', ! $validateCert);

        if (@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            throw new RuntimeException('STARTTLS negotiation with the mailbox server failed.');
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }
}
