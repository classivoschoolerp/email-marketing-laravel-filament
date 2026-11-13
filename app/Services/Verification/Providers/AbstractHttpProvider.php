<?php

declare(strict_types=1);

namespace App\Services\Verification\Providers;

use App\Services\Verification\VerificationProvider;
use App\Services\Verification\VerificationResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared plumbing for the external HTTP verification adapters (§2.9.12,
 * D-12): a pending request with a sane timeout, and the rule that vendor
 * failures (network error, non-2xx, malformed body) never throw — they map
 * to Unknown with a reason so bulk runs survive provider hiccups.
 */
abstract class AbstractHttpProvider implements VerificationProvider
{
    protected const TIMEOUT_SECONDS = 15;

    public function __construct(protected readonly ?string $apiKey) {}

    public function requiresApiKey(): bool
    {
        return true;
    }

    public function verify(string $email): VerificationResult
    {
        if ($this->requiresApiKey() && ($this->apiKey === null || $this->apiKey === '')) {
            return VerificationResult::unknown('missing_api_key');
        }

        try {
            $response = $this->request(mb_strtolower(trim($email)));
        } catch (Throwable $e) {
            return VerificationResult::unknown('provider_error: '.$e->getMessage());
        }

        if (! $response->successful()) {
            return VerificationResult::unknown('provider_http_'.$response->status(), (array) $response->json());
        }

        return $this->map((array) $response->json());
    }

    /** Perform the vendor call for one lower-cased address. */
    abstract protected function request(string $email): Response;

    /**
     * Normalise the vendor payload to a VerificationResult.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract protected function map(array $payload): VerificationResult;

    /** @return \Illuminate\Http\Client\PendingRequest */
    protected function http()
    {
        return Http::timeout(self::TIMEOUT_SECONDS)->acceptJson();
    }
}
