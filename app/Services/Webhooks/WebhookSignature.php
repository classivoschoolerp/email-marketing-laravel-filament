<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

/**
 * HMAC-SHA-256 request signing for outgoing webhooks (spec §2.14.2
 * F-2.14.2-001).
 *
 * The signed string is `{timestamp}.{raw body}` — the timestamp is inside the
 * MAC, so a captured delivery cannot be replayed later against a receiver
 * that enforces a freshness window. Headers sent with every delivery:
 *
 *   X-EMP-Event      the event name
 *   X-EMP-Delivery   the delivery uid (idempotency key for the receiver)
 *   X-EMP-Timestamp  unix seconds, the same value that is inside the MAC
 *   X-EMP-Signature  `sha256=<lowercase hex>`
 *
 * Receivers verify with a constant-time compare; verify() implements exactly
 * that so the docs page can point at a working reference implementation.
 */
final class WebhookSignature
{
    public const HEADER = 'X-EMP-Signature';

    public const TIMESTAMP_HEADER = 'X-EMP-Timestamp';

    public const EVENT_HEADER = 'X-EMP-Event';

    public const DELIVERY_HEADER = 'X-EMP-Delivery';

    public const PREFIX = 'sha256=';

    public function sign(string $body, string $secret, int $timestamp): string
    {
        return self::PREFIX.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** Constant-time verification, tolerating a missing/short signature. */
    public function verify(string $body, string $secret, int $timestamp, string $signature): bool
    {
        return hash_equals($this->sign($body, $secret, $timestamp), $signature);
    }

    /**
     * Full header set for one delivery.
     *
     * @return array<string, string>
     */
    public function headers(string $body, string $secret, string $event, string $deliveryUid, int $timestamp): array
    {
        return [
            'Content-Type' => 'application/json',
            'User-Agent' => 'EMP-Webhooks/1.0',
            self::EVENT_HEADER => $event,
            self::DELIVERY_HEADER => $deliveryUid,
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::HEADER => $this->sign($body, $secret, $timestamp),
        ];
    }
}
