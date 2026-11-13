<?php

declare(strict_types=1);

namespace App\Services\Dkim;

use RuntimeException;

/**
 * Generates the global DKIM RSA-2048 keypair (spec §3.25.10, F-3.25.10-001).
 *
 * Windows PHP builds ship without a default openssl.cnf; when the stock call
 * fails we retry with the minimal config bundled at resources/openssl/.
 */
final class GenerateDkimKeypair
{
    private const KEY_BITS = 2048;

    /**
     * @return array{private_key: string, public_key: string} PEM-encoded pair
     */
    public function __invoke(): array
    {
        [$key, $config] = $this->createKey();

        $privateKey = '';

        if (! openssl_pkey_export($key, $privateKey, null, $config)) {
            throw new RuntimeException('Failed to export the DKIM private key: '.$this->opensslErrors());
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['key'])) {
            throw new RuntimeException('Failed to read the DKIM public key: '.$this->opensslErrors());
        }

        return [
            'private_key' => $privateKey,
            'public_key' => (string) $details['key'],
        ];
    }

    /**
     * @return array{0: \OpenSSLAsymmetricKey, 1: array<string, mixed>}
     */
    private function createKey(): array
    {
        $options = [
            'private_key_bits' => self::KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $key = @openssl_pkey_new($options);

        if ($key !== false) {
            return [$key, []];
        }

        // Retry with the bundled minimal OpenSSL config (Windows).
        $config = ['config' => $this->bundledConfigPath()];
        $key = openssl_pkey_new($options + $config);

        if ($key === false) {
            throw new RuntimeException('Failed to generate the DKIM keypair: '.$this->opensslErrors());
        }

        return [$key, $config];
    }

    private function bundledConfigPath(): string
    {
        return str_replace('\\', '/', resource_path('openssl/openssl.cnf'));
    }

    private function opensslErrors(): string
    {
        $errors = [];

        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors === [] ? 'unknown OpenSSL error' : implode('; ', $errors);
    }
}
