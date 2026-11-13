<?php

declare(strict_types=1);

namespace App\Services\Verification\Providers;

use App\Services\Verification\VerificationResult;
use Illuminate\Http\Client\Response;

/**
 * DeBounce single-verify API (§2.9.12, D-12): GET
 * https://api.debounce.io/v1/?api=KEY&email=…. Response nests under
 * `debounce`: result ∈ "Safe to Send"|"Invalid"|"Risky"|"Unknown" with a
 * numeric `code` (1 syntax, 2 spam-trap, 3 disposable, 4 accept-all,
 * 5 deliverable, 6 invalid, 7 unknown, 8 role). We map on `result` and keep
 * `reason` (falling back to the code) as the sub-status.
 */
final class DebounceProvider extends AbstractHttpProvider
{
    public const ENDPOINT = 'https://api.debounce.io/v1/';

    public function key(): string
    {
        return 'debounce';
    }

    public function name(): string
    {
        return 'DeBounce';
    }

    protected function request(string $email): Response
    {
        return $this->http()->get(self::ENDPOINT, [
            'api' => (string) $this->apiKey,
            'email' => $email,
        ]);
    }

    protected function map(array $payload): VerificationResult
    {
        $body = (array) ($payload['debounce'] ?? []);
        $result = mb_strtolower((string) ($body['result'] ?? ''));
        $reason = (string) ($body['reason'] ?? ($body['code'] ?? ''));
        $reason = $reason !== '' ? $reason : null;

        return match ($result) {
            'safe to send' => VerificationResult::deliverable($reason, $payload),
            'invalid' => VerificationResult::undeliverable($reason, $payload),
            'risky' => VerificationResult::risky($reason, $payload),
            default => VerificationResult::unknown($reason ?? 'unrecognized_result', $payload),
        };
    }
}
