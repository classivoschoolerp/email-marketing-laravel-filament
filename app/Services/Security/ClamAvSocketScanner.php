<?php

declare(strict_types=1);

namespace App\Services\Security;

use Throwable;

/**
 * clamd scanner over the INSTREAM protocol (spec §3.25.15 F-3.25.15-006).
 *
 * Speaks clamd's wire format directly — no ext-clamav, no shelling out:
 *
 *   zINSTREAM\0  <4-byte BE length><chunk>…  <4 zero bytes>
 *   ← "stream: OK\0" | "stream: <Signature> FOUND\0" | "… ERROR\0"
 *
 * A TCP host/port pair comes from the security settings; passing a path
 * beginning with `/` or `unix:` switches to a unix socket, the usual clamd
 * deployment on Linux.
 *
 * Fail-open by contract (see {@see VirusScanner}): an unreachable daemon
 * yields a skipped result, mirroring D-49's SpamAssassin decision.
 */
final class ClamAvSocketScanner implements VirusScanner
{
    /** clamd's default StreamMaxLength is 25 MB; chunk well below it. */
    private const CHUNK = 8192;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 3310,
        private readonly float $timeout = 5.0,
    ) {}

    public function key(): string
    {
        return 'clamav';
    }

    public function scan(string $absolutePath): ScanResult
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return ScanResult::skipped('unreadable');
        }

        try {
            $socket = @stream_socket_client($this->endpoint(), $errno, $errstr, $this->timeout);
        } catch (Throwable $e) {
            return ScanResult::skipped($e->getMessage());
        }

        if ($socket === false) {
            return ScanResult::skipped($errstr !== '' ? $errstr : 'connection refused');
        }

        try {
            stream_set_timeout($socket, (int) ceil($this->timeout));

            fwrite($socket, "zINSTREAM\0");

            $handle = fopen($absolutePath, 'rb');

            if ($handle === false) {
                return ScanResult::skipped('unreadable');
            }

            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }

            fclose($handle);

            // Zero-length chunk terminates the stream.
            fwrite($socket, pack('N', 0));

            $response = (string) stream_get_contents($socket);
        } catch (Throwable $e) {
            return ScanResult::skipped($e->getMessage());
        } finally {
            @fclose($socket);
        }

        return $this->interpret($response);
    }

    private function interpret(string $response): ScanResult
    {
        $response = trim(str_replace("\0", '', $response));

        if ($response === '') {
            return ScanResult::skipped('empty response');
        }

        if (str_ends_with($response, 'OK')) {
            return ScanResult::clean();
        }

        if (str_ends_with($response, 'FOUND')) {
            // "stream: Eicar-Test-Signature FOUND"
            $signature = trim(preg_replace('/^.*?:\s*|\s*FOUND$/', '', $response) ?? '');

            return ScanResult::infected($signature === '' ? 'unknown' : $signature);
        }

        return ScanResult::skipped($response);
    }

    private function endpoint(): string
    {
        if (str_starts_with($this->host, 'unix:')) {
            return $this->host;
        }

        if (str_starts_with($this->host, '/')) {
            return 'unix://'.$this->host;
        }

        return 'tcp://'.$this->host.':'.$this->port;
    }
}
