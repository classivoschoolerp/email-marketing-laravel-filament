<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Tags a fixture integer with the exact MaxMind DB numeric type + width so
 * {@see MaxMindDbFixture} can emit uint16/uint32/uint64 correctly (PHP ints
 * alone cannot express the distinction).
 */
final class TypedNumber
{
    public function __construct(
        public readonly int $type,
        public readonly int $value,
        public readonly int $width,
    ) {}
}
