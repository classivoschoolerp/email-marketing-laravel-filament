<?php

declare(strict_types=1);

namespace App\Support\Geo;

use RuntimeException;

/**
 * Minimal, dependency-free reader for the MaxMind DB (.mmdb) binary format
 * (spec §3.23 F-3.23-002, D-10). `maxmind-db/reader` cannot be installed on
 * this machine (no composer network step is allowed mid-build), so the format
 * is implemented here against its public specification:
 *
 *   https://maxmind.github.io/MaxMind-DB/
 *
 * Implemented: metadata block discovery, binary search tree traversal for
 * IPv4 and IPv6 (24/28/32-bit records), and the data-section decoder
 * (pointer, utf8, double, bytes, uint16/32/64, map, int32, array, bool,
 * float). uint128 is returned as a raw byte string — no GeoLite2 field uses
 * it. Container/end-marker types only appear inside the metadata block and
 * are handled where the spec requires.
 *
 * The file is read through a single file handle with `fseek`, never loaded
 * whole: GeoLite2-City is ~60 MB.
 */
final class MaxMindDbReader
{
    private const METADATA_MARKER = "\xAB\xCD\xEFMaxMind.com";

    /** How far back from EOF the metadata marker is searched (spec: 128 KiB). */
    private const METADATA_MAX_SIZE = 131072;

    private const DATA_SECTION_SEPARATOR = 16;

    /** @var resource */
    private $handle;

    /** @var array<string, mixed> */
    private array $metadata;

    private int $nodeCount = 0;

    private int $recordSize = 0;

    private int $ipVersion = 6;

    /** Zero while the metadata block is being decoded — it carries no pointers. */
    private int $searchTreeSize = 0;

    private ?int $ipv4StartNode = null;

    public function __construct(private readonly string $path)
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new RuntimeException("MaxMind database not readable at [{$this->path}].");
        }

        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("MaxMind database could not be opened at [{$this->path}].");
        }

        $this->handle = $handle;
        $this->metadata = $this->readMetadata();

        $this->nodeCount = (int) ($this->metadata['node_count'] ?? 0);
        $this->recordSize = (int) ($this->metadata['record_size'] ?? 0);
        $this->ipVersion = (int) ($this->metadata['ip_version'] ?? 6);

        if ($this->nodeCount <= 0 || ! in_array($this->recordSize, [24, 28, 32], true)) {
            throw new RuntimeException('Unsupported MaxMind database metadata (node_count/record_size).');
        }

        $this->searchTreeSize = intdiv($this->nodeCount * $this->recordSize * 2, 8);
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /** @return array<string, mixed> the decoded metadata map */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function databaseType(): string
    {
        return (string) ($this->metadata['database_type'] ?? '');
    }

    public function buildEpoch(): int
    {
        return (int) ($this->metadata['build_epoch'] ?? 0);
    }

    /**
     * Look an address up. Returns the decoded record (usually a nested map)
     * or null when the address is not in the database.
     */
    public function get(string $ip): mixed
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            throw new RuntimeException("Invalid IP address [{$ip}].");
        }

        $bytes = unpack('C*', $packed);

        if ($bytes === false) {
            return null;
        }

        $bytes = array_values($bytes);
        $isIpv4 = count($bytes) === 4;

        if (! $isIpv4 && $this->ipVersion === 4) {
            throw new RuntimeException('IPv6 lookup attempted against an IPv4-only database.');
        }

        $node = $isIpv4 && $this->ipVersion === 6 ? $this->ipv4StartNode() : 0;
        $bitCount = count($bytes) * 8;

        for ($i = 0; $i < $bitCount; $i++) {
            if ($node >= $this->nodeCount) {
                break;
            }

            $bit = ($bytes[$i >> 3] >> (7 - ($i % 8))) & 1;
            $node = $this->readRecord($node, $bit);
        }

        if ($node === $this->nodeCount) {
            return null; // Explicit "not found" record.
        }

        if ($node < $this->nodeCount) {
            throw new RuntimeException('Corrupt MaxMind search tree (ran out of bits inside the tree).');
        }

        return $this->decodeAt($node - $this->nodeCount + $this->searchTreeSize)[0];
    }

    /**
     * Traversing 96 zero bits from the root gives the subtree holding IPv4
     * space in an IPv6 database. Computed once per reader instance.
     */
    private function ipv4StartNode(): int
    {
        if ($this->ipv4StartNode !== null) {
            return $this->ipv4StartNode;
        }

        $node = 0;

        for ($i = 0; $i < 96 && $node < $this->nodeCount; $i++) {
            $node = $this->readRecord($node, 0);
        }

        return $this->ipv4StartNode = $node;
    }

    private function readRecord(int $node, int $bit): int
    {
        $baseOffset = $node * intdiv($this->recordSize * 2, 8);

        return match ($this->recordSize) {
            24 => $this->uintFromBytes($this->read($baseOffset + $bit * 3, 3)),
            28 => $this->read28($baseOffset, $bit),
            default => $this->uintFromBytes($this->read($baseOffset + $bit * 4, 4)),
        };
    }

    private function read28(int $baseOffset, int $bit): int
    {
        $bytes = $this->read($baseOffset, 7);
        $middle = ord($bytes[3]);

        if ($bit === 0) {
            return (($middle & 0xF0) << 20) | $this->uintFromBytes(substr($bytes, 0, 3));
        }

        return (($middle & 0x0F) << 24) | $this->uintFromBytes(substr($bytes, 4, 3));
    }

    /* ================================================================ *
     *  Data section decoder
     * ================================================================ */

    /**
     * @return array{0: mixed, 1: int} decoded value + offset just past it
     */
    private function decodeAt(int $offset): array
    {
        $control = ord($this->read($offset, 1));
        $offset++;

        $type = $control >> 5;

        if ($type === 0) {
            $type = ord($this->read($offset, 1)) + 7;
            $offset++;
        }

        if ($type === 1) {
            return $this->decodePointer($control, $offset);
        }

        [$size, $offset] = $this->decodeSize($control, $offset);

        return match ($type) {
            2 => [$this->read($offset, $size), $offset + $size],
            3 => [$this->decodeDouble($offset, $size), $offset + $size],
            4 => [$this->read($offset, $size), $offset + $size],
            5, 6, 9, 10 => [$this->decodeUint($offset, $size, $type), $offset + $size],
            7 => $this->decodeMap($size, $offset),
            8 => [$this->decodeInt32($offset, $size), $offset + $size],
            11 => $this->decodeArray($size, $offset),
            14 => [$size === 1, $offset],
            15 => [$this->decodeFloat($offset, $size), $offset + $size],
            // 12 (container) / 13 (end marker) never appear as stored values.
            default => throw new RuntimeException("Unsupported MaxMind data type [{$type}]."),
        };
    }

    /** @return array{0: int, 1: int} */
    private function decodeSize(int $control, int $offset): array
    {
        $size = $control & 0x1F;

        if ($size < 29) {
            return [$size, $offset];
        }

        $extraBytes = $size - 28;
        $value = $this->uintFromBytes($this->read($offset, $extraBytes));
        $offset += $extraBytes;

        return [match ($size) {
            29 => 29 + $value,
            30 => 285 + $value,
            default => 65821 + $value,
        }, $offset];
    }

    /** @return array{0: mixed, 1: int} */
    private function decodePointer(int $control, int $offset): array
    {
        $size = ($control >> 3) & 0x3;
        $length = $size + 1;
        $prefix = $size === 3 ? 0 : $control & 0x7;

        $value = ($prefix << ($length * 8)) | $this->uintFromBytes($this->read($offset, $length));
        $offset += $length;

        $pointer = $value + match ($size) {
            0 => 0,
            1 => 2048,
            2 => 526336,
            default => 0,
        };

        // Pointers are relative to the start of the data section.
        $target = $this->searchTreeSize + self::DATA_SECTION_SEPARATOR + $pointer;

        return [$this->decodeAt($target)[0], $offset];
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private function decodeMap(int $size, int $offset): array
    {
        $map = [];

        for ($i = 0; $i < $size; $i++) {
            [$key, $offset] = $this->decodeAt($offset);
            [$value, $offset] = $this->decodeAt($offset);
            $map[(string) $key] = $value;
        }

        return [$map, $offset];
    }

    /** @return array{0: list<mixed>, 1: int} */
    private function decodeArray(int $size, int $offset): array
    {
        $items = [];

        for ($i = 0; $i < $size; $i++) {
            [$value, $offset] = $this->decodeAt($offset);
            $items[] = $value;
        }

        return [$items, $offset];
    }

    private function decodeUint(int $offset, int $size, int $type): int|string
    {
        if ($size === 0) {
            return $type === 10 ? '' : 0;
        }

        $bytes = $this->read($offset, $size);

        // uint128: no GeoLite2 field uses it — hand back the raw bytes.
        if ($type === 10) {
            return $bytes;
        }

        return $this->uintFromBytes($bytes);
    }

    private function decodeInt32(int $offset, int $size): int
    {
        if ($size === 0) {
            return 0;
        }

        $value = $this->uintFromBytes($this->read($offset, $size));

        // Sign-extend from the stored width.
        $signBit = 1 << ($size * 8 - 1);

        return ($value & $signBit) !== 0 ? $value - (1 << ($size * 8)) : $value;
    }

    private function decodeDouble(int $offset, int $size): float
    {
        if ($size !== 8) {
            throw new RuntimeException('Invalid MaxMind double size.');
        }

        /** @var array{1: float} $unpacked */
        $unpacked = unpack('E', $this->read($offset, 8));

        return (float) $unpacked[1];
    }

    private function decodeFloat(int $offset, int $size): float
    {
        if ($size !== 4) {
            throw new RuntimeException('Invalid MaxMind float size.');
        }

        /** @var array{1: float} $unpacked */
        $unpacked = unpack('G', $this->read($offset, 4));

        return (float) $unpacked[1];
    }

    private function uintFromBytes(string $bytes): int
    {
        $value = 0;

        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }

        return $value;
    }

    /* ================================================================ *
     *  Metadata
     * ================================================================ */

    /** @return array<string, mixed> */
    private function readMetadata(): array
    {
        $size = (int) filesize($this->path);
        $window = min($size, self::METADATA_MAX_SIZE);
        $start = $size - $window;

        fseek($this->handle, $start);
        $tail = (string) stream_get_contents($this->handle, $window);

        $position = strrpos($tail, self::METADATA_MARKER);

        if ($position === false) {
            throw new RuntimeException('Not a MaxMind DB file (metadata marker missing).');
        }

        $metadataOffset = $start + $position + strlen(self::METADATA_MARKER);

        // The metadata block is decoded with offsets relative to file start;
        // it contains no pointers, so the data-section base is irrelevant.
        $decoded = $this->decodeAt($metadataOffset)[0];

        if (! is_array($decoded)) {
            throw new RuntimeException('Corrupt MaxMind metadata block.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function read(int $offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        fseek($this->handle, $offset);
        $bytes = fread($this->handle, $length);

        if ($bytes === false || strlen($bytes) !== $length) {
            throw new RuntimeException("Unexpected end of MaxMind database at offset {$offset}.");
        }

        return $bytes;
    }
}
