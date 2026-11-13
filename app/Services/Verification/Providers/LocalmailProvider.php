<?php

declare(strict_types=1);

namespace App\Services\Verification\Providers;

use App\Services\Verification\VerificationResult;
use Illuminate\Http\Client\Response;

/**
 * Localmail verification adapter (§2.9.12, D-12). Localmail publishes no
 * widely-mirrored API reference, so this adapter targets the conventional
 * shape its integrations use — GET {base}/verify?email=…&api_key=… →
 * {result: deliverable|undeliverable|risky|unknown, reason} — and the base
 * URL is configurable (config('emp-verification.providers.localmail.base_url'))
 * for self-hosted instances. Contract locked by Http::fake tests; adjust the
 * base URL/mapping here if the vendor's live API differs (no core change
 * needed elsewhere).
 */
final class LocalmailProvider extends AbstractHttpProvider
{
    public const DEFAULT_BASE_URL = 'https://api.localmail.io/v1';

    public function key(): string
    {
        return 'localmail';
    }

    public function name(): string
    {
        return 'Localmail';
    }

    protected function request(string $email): Response
    {
        $configured = config('emp-verification.providers.localmail.base_url');
        $base = rtrim(is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');

        return $this->http()->get($base.'/verify', [
            'email' => $email,
            'api_key' => (string) $this->apiKey,
        ]);
    }

    protected function map(array $payload): VerificationResult
    {
        $result = (string) ($payload['result'] ?? '');
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;

        return match ($result) {
            'deliverable' => VerificationResult::deliverable($reason, $payload),
            'undeliverable' => VerificationResult::undeliverable($reason, $payload),
            'risky' => VerificationResult::risky($reason, $payload),
            default => VerificationResult::unknown($reason ?? 'unrecognized_result', $payload),
        };
    }
}
