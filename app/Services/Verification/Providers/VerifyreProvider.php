<?php

declare(strict_types=1);

namespace App\Services\Verification\Providers;

use App\Services\Verification\VerificationResult;
use Illuminate\Http\Client\Response;

/**
 * Verifyre verification adapter (§2.9.12, D-12). Verifyre publishes no
 * widely-mirrored API reference, so this adapter targets the conventional
 * shape its integrations use — POST {base}/verify {"email": …} with a
 * Bearer token → {status: deliverable|undeliverable|risky|unknown, reason}
 * — and the base URL is configurable
 * (config('emp-verification.providers.verifyre.base_url')). Contract locked
 * by Http::fake tests; adjust the base URL/mapping here if the vendor's
 * live API differs (no core change needed elsewhere).
 */
final class VerifyreProvider extends AbstractHttpProvider
{
    public const DEFAULT_BASE_URL = 'https://api.verifyre.com/v1';

    public function key(): string
    {
        return 'verifyre';
    }

    public function name(): string
    {
        return 'Verifyre';
    }

    protected function request(string $email): Response
    {
        $configured = config('emp-verification.providers.verifyre.base_url');
        $base = rtrim(is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');

        return $this->http()
            ->withToken((string) $this->apiKey)
            ->post($base.'/verify', ['email' => $email]);
    }

    protected function map(array $payload): VerificationResult
    {
        $status = (string) ($payload['status'] ?? '');
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;

        return match ($status) {
            'deliverable' => VerificationResult::deliverable($reason, $payload),
            'undeliverable' => VerificationResult::undeliverable($reason, $payload),
            'risky' => VerificationResult::risky($reason, $payload),
            default => VerificationResult::unknown($reason ?? 'unrecognized_status', $payload),
        };
    }
}
