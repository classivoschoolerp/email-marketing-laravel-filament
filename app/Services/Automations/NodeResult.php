<?php

declare(strict_types=1);

namespace App\Services\Automations;

use Carbon\CarbonInterface;

/**
 * Outcome of executing one node (WP-21). Final class, not readonly (D-46
 * convention for engine DTOs).
 */
final class NodeResult
{
    public const CONTINUE = 'continue';

    public const SLEEP = 'sleep';

    public const WAIT = 'wait';

    public const COMPLETE = 'complete';

    public const EXIT = 'exit';

    public const FAIL = 'fail';

    public const JUMP = 'jump';

    /** @param array<string, mixed> $detail */
    private function __construct(
        public string $kind,
        public string $branch = 'next',
        public ?CarbonInterface $until = null,
        public ?string $waitingFor = null,
        public ?string $reason = null,
        public ?string $error = null,
        public ?string $targetUid = null,
        public array $detail = [],
    ) {}

    /** @param array<string, mixed> $detail */
    public static function continue(string $branch = 'next', array $detail = []): self
    {
        return new self(self::CONTINUE, branch: $branch, detail: $detail);
    }

    /** @param array<string, mixed> $detail */
    public static function sleep(CarbonInterface $until, ?string $waitingFor = null, array $detail = []): self
    {
        return new self(self::SLEEP, until: $until, waitingFor: $waitingFor, detail: $detail);
    }

    /** Park on a named event, optionally with a timeout alarm. */
    public static function wait(string $event, ?CarbonInterface $timeoutAt, array $detail = []): self
    {
        return new self(self::WAIT, until: $timeoutAt, waitingFor: $event, detail: $detail);
    }

    /** @param array<string, mixed> $detail */
    public static function complete(string $reason, array $detail = []): self
    {
        return new self(self::COMPLETE, reason: $reason, detail: $detail);
    }

    /** @param array<string, mixed> $detail */
    public static function exit(string $reason, array $detail = []): self
    {
        return new self(self::EXIT, reason: $reason, detail: $detail);
    }

    public static function fail(string $error): self
    {
        return new self(self::FAIL, error: $error);
    }

    /** @param array<string, mixed> $detail */
    public static function jump(string $targetUid, array $detail = []): self
    {
        return new self(self::JUMP, targetUid: $targetUid, detail: $detail);
    }
}
