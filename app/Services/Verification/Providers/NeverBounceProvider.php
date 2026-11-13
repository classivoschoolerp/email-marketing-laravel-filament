<?php

declare(strict_types=1);

namespace App\Services\Verification\Providers;

use App\Services\Verification\VerificationResult;
use Illuminate\Http\Client\Response;

/**
 * NeverBounce single-check API (§2.9.12, D-12): GET
 * https://api.neverbounce.com/v4/single/check?key=…&email=…. Their
 * vocabulary: result ∈ valid|invalid|disposable|catchall|unknown inside a
 * {status: "success"} envelope. Mapping: valid → deliverable; invalid →
 * undeliverable; disposable/catchall → risky; unknown (or a non-success
 * envelope) → unknown.
 */
final class NeverBounceProvider extends AbstractHttpProvider
{
    public const ENDPOINT = 'https://api.neverbounce.com/v4/single/check';

    public function key(): string
    {
        return 'neverbounce';
    }

    public function name(): string
    {
        return 'NeverBounce';
    }

    protected function request(string $email): Response
    {
        return $this->http()->get(self::ENDPOINT, [
            'key' => (string) $this->apiKey,
            'email' => $email,
        ]);
    }

    protected function map(array $payload): VerificationResult
    {
        if (($payload['status'] ?? null) !== 'success') {
            return VerificationResult::unknown((string) ($payload['message'] ?? 'provider_error'), $payload);
        }

        $result = (string) ($payload['result'] ?? '');

        return match ($result) {
            'valid' => VerificationResult::deliverable($result, $payload),
            'invalid' => VerificationResult::undeliverable($result, $payload),
            'disposable', 'catchall' => VerificationResult::risky($result, $payload),
            default => VerificationResult::unknown($result !== '' ? $result : 'unrecognized_result', $payload),
        };
    }
}
