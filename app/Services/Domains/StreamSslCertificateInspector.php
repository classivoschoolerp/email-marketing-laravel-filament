<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Contracts\Domains\SslCertificateInspector;
use Throwable;

/**
 * Live TLS inspection over a raw socket (spec §6.4 F-6.4-004).
 *
 * `verify_peer` is FALSE on purpose. The point of this probe is to report
 * what the host presents — including an expired, self-signed or
 * wrong-hostname certificate, which are exactly the states an operator needs
 * to see. Verifying would turn every one of those into "connection failed"
 * and destroy the diagnostic. The certificate is then judged in PHP, where
 * the individual failure can be named (see {@see DomainSslStatus}).
 *
 * Nothing here trusts the certificate for any purpose: no request is made
 * over the connection, and the socket is closed as soon as the peer
 * certificate has been read.
 */
class StreamSslCertificateInspector implements SslCertificateInspector
{
    public function inspect(string $host, int $port = 443, int $timeout = 5): array
    {
        $empty = [
            'reachable' => false,
            'subject' => null,
            'issuer' => null,
            'valid_from' => null,
            'valid_to' => null,
            'names' => [],
            'error' => null,
        ];

        if (trim($host) === '') {
            return ['error' => __('themes.domains.ssl_errors.no_host')] + $empty;
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $client = @stream_socket_client(
            'ssl://'.$host.':'.$port,
            $errorCode,
            $errorMessage,
            max(1, $timeout),
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return ['error' => trim((string) $errorMessage) ?: (string) $errorCode] + $empty;
        }

        try {
            $params = stream_context_get_params($client);
            $certificate = $params['options']['ssl']['peer_certificate'] ?? null;

            if ($certificate === null) {
                return ['reachable' => true, 'error' => __('themes.domains.ssl_errors.no_certificate')] + $empty;
            }

            $parsed = openssl_x509_parse($certificate);

            if ($parsed === false) {
                return ['reachable' => true, 'error' => __('themes.domains.ssl_errors.unparsable')] + $empty;
            }

            return [
                'reachable' => true,
                'subject' => $this->flatten($parsed['subject'] ?? []),
                'issuer' => $this->flatten($parsed['issuer'] ?? []),
                'valid_from' => isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null,
                'valid_to' => isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null,
                'names' => $this->names($parsed),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['reachable' => true, 'error' => $e->getMessage()] + $empty;
        } finally {
            fclose($client);
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private function names(array $parsed): array
    {
        $names = [];

        $cn = $parsed['subject']['CN'] ?? null;

        if (is_string($cn) && $cn !== '') {
            $names[] = mb_strtolower($cn);
        }

        $san = (string) ($parsed['extensions']['subjectAltName'] ?? '');

        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);

            if (str_starts_with($entry, 'DNS:')) {
                $names[] = mb_strtolower(substr($entry, 4));
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function flatten(array $parts): ?string
    {
        $preferred = [];

        foreach (['CN', 'O', 'OU'] as $key) {
            $value = $parts[$key] ?? null;

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            if (is_string($value) && $value !== '') {
                $preferred[] = $value;
            }
        }

        return $preferred === [] ? null : implode(', ', $preferred);
    }
}
