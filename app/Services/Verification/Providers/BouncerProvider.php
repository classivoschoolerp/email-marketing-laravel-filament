<?php

declare(strict_types=1);

namespace App\Services\Verification\Providers;

use App\Services\Verification\VerificationResult;
use Illuminate\Http\Client\Response;

/**
 * Bouncer (usebouncer.com) verify API (§2.9.12, D-12): GET
 * https://api.usebouncer.com/v1.1/email/verify?email=… authenticated with
 * the x-api-key header. Response `status` is already our vocabulary
 * (deliverable|undeliverable|risky|unknown); `reason` carries the
 * sub-status.
 */
final class BouncerProvider extends AbstractHttpProvider
{
    public const ENDPOINT = 'https://api.usebouncer.com/v1.1/email/verify';

    public function key(): string
    {
        return 'bouncer';
    }

    public function name(): string
    {
        return 'Bouncer';
    }

    protected function request(string $email): Response
    {
        return $this->http()
            ->withHeaders(['x-api-key' => (string) $this->apiKey])
            ->get(self::ENDPOINT, ['email' => $email]);
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
