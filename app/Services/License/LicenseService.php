<?php

declare(strict_types=1);

namespace App\Services\License;

use App\Settings\LicenseSettings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * License validation against a configurable endpoint (spec §3.25.12, D-09).
 *
 * - No endpoint configured → valid self-hosted mode.
 * - Signed responses are verified when config('license.public_key') is set
 *   (stub: verification is skipped when no key is configured).
 * - Unreachable server → status "unreachable" (never locks the app).
 */
class LicenseService
{
    public function validate(?string $key): LicenseResult
    {
        $endpoint = config('license.endpoint');

        if (! is_string($endpoint) || trim($endpoint) === '') {
            return LicenseResult::selfHosted();
        }

        if ($key === null || trim($key) === '') {
            return new LicenseResult(
                status: LicenseSettings::STATUS_INVALID,
                message: __('settings.license.messages.missing_key'),
            );
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('license.timeout', 10))
                ->post($endpoint, [
                    'license_key' => $key,
                    'domain' => $this->applicationHost(),
                ]);
        } catch (Throwable) {
            return new LicenseResult(
                status: LicenseSettings::STATUS_UNREACHABLE,
                message: __('settings.license.messages.unreachable'),
            );
        }

        if ($response->failed()) {
            return new LicenseResult(
                status: LicenseSettings::STATUS_UNREACHABLE,
                message: __('settings.license.messages.unreachable'),
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        if (! $this->verifySignature($response, $payload)) {
            return new LicenseResult(
                status: LicenseSettings::STATUS_INVALID,
                message: __('settings.license.messages.bad_signature'),
            );
        }

        if (($payload['valid'] ?? false) !== true) {
            return new LicenseResult(
                status: LicenseSettings::STATUS_INVALID,
                message: is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : __('settings.license.messages.invalid'),
            );
        }

        $allowedDomains = array_values(array_filter(
            (array) ($payload['allowed_domains'] ?? []),
            'is_string',
        ));

        return new LicenseResult(
            status: LicenseSettings::STATUS_VALID,
            type: is_string($payload['type'] ?? null) ? $payload['type'] : null,
            expiresAt: is_string($payload['expires_at'] ?? null) ? $payload['expires_at'] : null,
            allowedDomains: $allowedDomains,
            renewalUrl: is_string($payload['renewal_url'] ?? null) ? $payload['renewal_url'] : null,
            message: is_string($payload['message'] ?? null) ? $payload['message'] : null,
        );
    }

    /**
     * Validates the given key (or the stored one) and persists the outcome
     * into the license settings group (F-3.25.12-001/002).
     */
    public function validateAndStore(LicenseSettings $settings, ?string $key = null): LicenseResult
    {
        if (func_num_args() >= 2) {
            $settings->license_key = ($key === null || trim($key) === '') ? null : trim($key);
        }

        $result = $this->validate($settings->license_key);

        $settings->status = $result->status;
        $settings->last_checked_at = now()->toIso8601String();

        // Keep the last known license details on an unreachable server; a
        // network blip must not wipe a previously validated license.
        if ($result->status !== LicenseSettings::STATUS_UNREACHABLE) {
            $settings->type = $result->type;
            $settings->expires_at = $result->expiresAt;
            $settings->allowed_domains = $result->allowedDomains;
            $settings->renewal_url = $result->renewalUrl;
        }

        $settings->save();

        return $result;
    }

    /**
     * Signed-response verification stub (D-09): responses carry an
     * X-Signature header (or "signature" field) — base64 RSA-SHA256 over the
     * raw body. Skipped entirely when no public key is configured.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function verifySignature(Response $response, array $payload): bool
    {
        $publicKey = config('license.public_key');

        if (! is_string($publicKey) || trim($publicKey) === '') {
            return true;
        }

        $signature = $response->header('X-Signature');

        if ($signature === '' && is_string($payload['signature'] ?? null)) {
            $signature = $payload['signature'];
        }

        $decoded = base64_decode($signature, true);

        if ($decoded === false || $decoded === '') {
            return false;
        }

        return openssl_verify($response->body(), $decoded, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    protected function applicationHost(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }
}
