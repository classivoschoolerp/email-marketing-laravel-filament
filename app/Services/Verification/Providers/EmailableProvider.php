<?php

declare(strict_types=1);

namespace App\Services\Verification\Providers;

use App\Services\Verification\VerificationResult;
use Illuminate\Http\Client\Response;

/**
 * Emailable single-verify API (§2.9.12, D-12): GET
 * https://api.emailable.com/v1/verify?email=…&api_key=…. Response `state` is
 * already our vocabulary: deliverable|undeliverable|risky|unknown; `reason`
 * carries the sub-status (accepted_email, invalid_domain, low_quality, …).
 */
final class EmailableProvider extends AbstractHttpProvider
{
    public const ENDPOINT = 'https://api.emailable.com/v1/verify';

    public function key(): string
    {
        return 'emailable';
    }

    public function name(): string
    {
        return 'Emailable';
    }

    protected function request(string $email): Response
    {
        return $this->http()->get(self::ENDPOINT, [
            'email' => $email,
            'api_key' => (string) $this->apiKey,
        ]);
    }

    protected function map(array $payload): VerificationResult
    {
        $state = (string) ($payload['state'] ?? '');
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;

        return match ($state) {
            'deliverable' => VerificationResult::deliverable($reason, $payload),
            'undeliverable' => VerificationResult::undeliverable($reason, $payload),
            'risky' => VerificationResult::risky($reason, $payload),
            default => VerificationResult::unknown($reason ?? 'unrecognized_state', $payload),
        };
    }
}
