<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\SpamCheck\SpamdClient;
use RuntimeException;

/**
 * Canned-transcript spamd client (D-08): returns a fixed SPAMD response and
 * records every SPAMC request for framing assertions.
 */
final class FakeSpamdClient implements SpamdClient
{
    /** @var list<string> */
    public array $requests = [];

    public function __construct(
        private readonly ?string $response,
        private readonly bool $unreachable = false,
    ) {}

    public static function unreachable(): self
    {
        return new self(null, unreachable: true);
    }

    public function exchange(string $request): string
    {
        $this->requests[] = $request;

        if ($this->unreachable || $this->response === null) {
            throw new RuntimeException('spamd unreachable at 127.0.0.1:783: [111] Connection refused');
        }

        return $this->response;
    }
}
