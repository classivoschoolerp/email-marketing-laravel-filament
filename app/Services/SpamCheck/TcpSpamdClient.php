<?php

declare(strict_types=1);

namespace App\Services\SpamCheck;

use RuntimeException;

/**
 * Production spamd transport (D-08): plain TCP to the configured host/port
 * (spamd default 783). Writes the request, half-closes the write side so
 * spamd knows the body ended, then drains the full response.
 */
class TcpSpamdClient implements SpamdClient
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function exchange(string $request): string
    {
        $socket = @stream_socket_client(
            'tcp://'.$this->host.':'.$this->port,
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds,
        );

        if ($socket === false) {
            throw new RuntimeException(
                "spamd unreachable at {$this->host}:{$this->port}: [{$errorCode}] {$errorMessage}"
            );
        }

        try {
            stream_set_timeout($socket, $this->timeoutSeconds);

            if (@fwrite($socket, $request) === false) {
                throw new RuntimeException('spamd write failed');
            }

            stream_socket_shutdown($socket, STREAM_SHUT_WR);

            $response = stream_get_contents($socket);

            if ($response === false || $response === '') {
                throw new RuntimeException('spamd returned no response');
            }

            return $response;
        } finally {
            fclose($socket);
        }
    }
}
