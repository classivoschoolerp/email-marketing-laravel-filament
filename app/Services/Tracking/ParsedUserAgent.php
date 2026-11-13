<?php

declare(strict_types=1);

namespace App\Services\Tracking;

/**
 * Result of UserAgentParser::parse() — the device/os/browser/client columns
 * logged on every tracking hit (spec §1.7 F-1.7-006). Null = undetected.
 */
final readonly class ParsedUserAgent
{
    public function __construct(
        public ?string $device,
        public ?string $os,
        public ?string $browser,
        public ?string $client,
    ) {}

    /** @return array{device: ?string, os: ?string, browser: ?string, client: ?string} */
    public function toArray(): array
    {
        return [
            'device' => $this->device,
            'os' => $this->os,
            'browser' => $this->browser,
            'client' => $this->client,
        ];
    }
}
