<?php

declare(strict_types=1);

namespace App\Sending\Support;

/**
 * Minimal AWS Signature Version 4 signer (WP-14, for the amazon-ses-api
 * driver — no AWS SDK dependency). Implements the four documented steps:
 * canonical request → string to sign → derived signing key → signature.
 * Unit-tested against the published AWS documentation vector (AKIDEXAMPLE,
 * 20150830, iam ListUsers) in tests/Unit/Sending/SigV4SignerTest.php.
 */
class SigV4Signer
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * Sign a request; returns the headers to attach (given headers + host,
     * x-amz-date and Authorization).
     *
     * @param  array<string, string>  $headers  header name => value (Host added automatically)
     * @param  string  $amzDate  20150830T123600Z format (UTC)
     * @return array<string, string>
     */
    public function sign(
        string $method,
        string $uri,
        array $headers,
        string $payload,
        string $accessKey,
        string $secretKey,
        string $region,
        string $service,
        string $amzDate,
    ): array {
        $parts = parse_url($uri);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';

        $headers = array_merge($headers, [
            'host' => $host,
            'x-amz-date' => $amzDate,
        ]);

        $canonicalHeaders = $this->canonicalHeaders($headers);
        $signedHeaders = implode(';', array_keys($canonicalHeaders));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalPath($path),
            $this->canonicalQuery($query),
            implode('', array_map(
                static fn (string $name, string $value): string => $name.':'.$value."\n",
                array_keys($canonicalHeaders),
                $canonicalHeaders,
            )),
            $signedHeaders,
            hash('sha256', $payload),
        ]);

        $date = substr($amzDate, 0, 8);
        $scope = $date.'/'.$region.'/'.$service.'/aws4_request';

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac(
            'sha256',
            $stringToSign,
            $this->signingKey($secretKey, $date, $region, $service),
        );

        return [
            'X-Amz-Date' => $amzDate,
            'Authorization' => sprintf(
                '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                self::ALGORITHM,
                $accessKey,
                $scope,
                $signedHeaders,
                $signature,
            ),
        ];
    }

    /** SHA-256 hex of the canonical request — exposed for the doc-vector test. */
    public function canonicalRequestHash(
        string $method,
        string $uri,
        array $headers,
        string $payload,
        string $amzDate,
    ): string {
        $parts = parse_url($uri);

        $headers = array_merge($headers, [
            'host' => $parts['host'] ?? '',
            'x-amz-date' => $amzDate,
        ]);

        $canonicalHeaders = $this->canonicalHeaders($headers);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalPath($parts['path'] ?? '/'),
            $this->canonicalQuery($parts['query'] ?? ''),
            implode('', array_map(
                static fn (string $name, string $value): string => $name.':'.$value."\n",
                array_keys($canonicalHeaders),
                $canonicalHeaders,
            )),
            implode(';', array_keys($canonicalHeaders)),
            hash('sha256', $payload),
        ]);

        return hash('sha256', $canonicalRequest);
    }

    /** @return array<string, string> lowercased, trimmed, sorted by name */
    private function canonicalHeaders(array $headers): array
    {
        $canonical = [];

        foreach ($headers as $name => $value) {
            $canonical[strtolower(trim((string) $name))] = preg_replace('/\s+/', ' ', trim((string) $value));
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    private function canonicalPath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        // URI-encode each segment (already-encoded input is preserved).
        return implode('/', array_map(
            static fn (string $segment): string => str_replace('%7E', '~', rawurlencode(rawurldecode($segment))),
            explode('/', $path),
        ));
    }

    private function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($key)), rawurlencode(rawurldecode($value))];
        }

        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn (array $p): string => $p[0].'='.$p[1], $pairs));
    }

    private function signingKey(string $secretKey, string $date, string $region, string $service): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4'.$secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
