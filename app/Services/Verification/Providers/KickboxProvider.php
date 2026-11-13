<?php

declare(strict_types=1);

namespace App\Services\Verification\Providers;

use App\Services\Verification\VerificationResult;
use Illuminate\Http\Client\Response;

/**
 * Kickbox verify API (§2.9.12, D-12): GET
 * https://api.kickbox.com/v2/verify?email=…&apikey=…. Response `result` is
 * already our vocabulary (deliverable|undeliverable|risky|unknown); `reason`
 * carries the sub-status (accepted_email, rejected_email, invalid_smtp, …).
 */
final class KickboxProvider extends AbstractHttpProvider
{
    public const ENDPOINT = 'https://api.kickbox.com/v2/verify';

    public function key(): string
    {
        return 'kickbox';
    }

    public function name(): string
    {
        return 'Kickbox';
    }

    protected function request(string $email): Response
    {
        return $this->http()->get(self::ENDPOINT, [
            'email' => $email,
            'apikey' => (string) $this->apiKey,
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
