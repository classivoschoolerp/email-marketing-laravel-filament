<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Models\DeliveryServer;
use App\Sending\Message;
use App\Sending\SendingDriver;
use App\Sending\SendResult;

/**
 * §4.6 F-4.6-009 benchmark sink (WP-26): accepts every message, talks to
 * nothing, counts what it was given.
 *
 * This exists so `emp:benchmark-send` can drive the REAL send pipeline —
 * recipient snapshot, batching, per-message job, merge/spintax rendering,
 * tracking rewrites, MIME build, delivery logging, live counters — with the
 * only network hop removed, isolating the platform's own throughput from
 * the SMTP/API vendor's.
 *
 * Deliberately NOT registered in config('emp-sending.drivers'): it must
 * never be selectable as a delivery server type. The benchmark command
 * registers it on the DriverRegistry at runtime, for that process only.
 */
final class NullDriver implements SendingDriver
{
    public const KEY = 'null-benchmark';

    public int $count = 0;

    /** Total bytes of rendered body handed to the driver (payload sanity). */
    public int $bytes = 0;

    /**
     * @param  string  $key  registry key this instance answers to. The
     *                       benchmark substitutes it for a REAL driver key
     *                       (e.g. 'smtp') because the D-21/D-47 type
     *                       whitelist only lets a customer use whitelisted
     *                       server types — the null sink must impersonate a
     *                       legitimate type rather than weaken that check.
     */
    public function __construct(private readonly string $key = self::KEY) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Null (benchmark sink)';
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        $this->count++;
        $this->bytes += strlen((string) $message->html) + strlen((string) $message->text);

        return SendResult::success('null-'.$this->count);
    }

    /** @return array<string, string> */
    public function validateConfig(DeliveryServer $server): array
    {
        return [];
    }

    /** @return array<string, bool|int|string> */
    public function capabilities(): array
    {
        return ['transport' => 'null', 'attachments' => true, 'custom_headers' => true];
    }

    /** @return list<array<string, mixed>> */
    public function formSchema(): array
    {
        return [];
    }
}
