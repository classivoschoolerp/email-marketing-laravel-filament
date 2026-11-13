<?php

declare(strict_types=1);

namespace App\Services\Verification\Providers;

use App\Services\Verification\VerificationResult;
use Illuminate\Http\Client\Response;

/**
 * ZeroBounce validate API (§2.9.12, D-12): GET
 * https://api.zerobounce.net/v2/validate?api_key=…&email=…&ip_address=.
 * Their vocabulary: status ∈ valid|invalid|catch-all|unknown|spamtrap|
 * abuse|do_not_mail (+ sub_status). Mapping: valid → deliverable; invalid →
 * undeliverable; catch-all/spamtrap/abuse/do_not_mail → risky (sendable but
 * dangerous); anything else → unknown.
 */
final class ZeroBounceProvider extends AbstractHttpProvider
{
    public const ENDPOINT = 'https://api.zerobounce.net/v2/validate';

    public function key(): string
    {
        return 'zerobounce';
    }

    public function name(): string
    {
        return 'ZeroBounce';
    }

    protected function request(string $email): Response
    {
        return $this->http()->get(self::ENDPOINT, [
            'api_key' => (string) $this->apiKey,
            'email' => $email,
            'ip_address' => '',
        ]);
    }

    protected function map(array $payload): VerificationResult
    {
        $status = (string) ($payload['status'] ?? '');
        $reason = trim((string) ($payload['sub_status'] ?? ''));
        $reason = $reason !== '' ? $reason : $status;

        return match ($status) {
            'valid' => VerificationResult::deliverable($reason, $payload),
            'invalid' => VerificationResult::undeliverable($reason, $payload),
            'catch-all', 'spamtrap', 'abuse', 'do_not_mail' => VerificationResult::risky($reason, $payload),
            default => VerificationResult::unknown($reason !== '' ? $reason : 'unrecognized_status', $payload),
        };
    }
}
