<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Builds a real, byte-correct MaxMind DB (.mmdb) file in memory.
 *
 * WP-24 (§3.23 F-3.23-002) implements a dependency-free .mmdb reader
 * (App\Support\Geo\MaxMindDbReader). Testing it against a checked-in binary
 * blob would prove nothing about the FORMAT — so this fixture writes the
 * format from the same public specification, independently of the reader:
 * search tree → 16-byte separator → data section → metadata marker →
 * metadata block.
 *
 * https://maxmind.github.io/MaxMind-DB/
 */
final class MaxMindDbFixture
{
    private const MARKER = "\xAB\xCD\xEFMaxMind.com";

    /**
     * Records stay tagged tuples ('node' | 'data' | 'empty') until node_count
     * is known and they can be resolved to their numeric values.
     *
     * @var list<array{left: array{0: string, 1: int}, right: array{0: string, 1: int}}>
     */
    private array $tree = [];

    /** @var array<string, int> data key => offset within the data section */
    private array $dataOffsets = [];

    private string $dataSection = '';

    public function __construct()
    {
        $this->tree[] = ['left' => ['empty', 0], 'right' => ['empty', 0]];
    }

    /**
     * Convenience: a one-network IPv4 city database that mirrors the shape of
     * a real GeoLite2-City record.
     */
    public static function cityDatabase(string $path): string
    {
        $fixture = new self;

        $fixture->addRecord('demo', [
            'continent' => ['code' => 'NA', 'names' => ['en' => 'North America']],
            'country' => ['iso_code' => 'US', 'names' => ['en' => 'United States', 'fr' => 'États-Unis']],
            'registered_country' => ['iso_code' => 'US', 'names' => ['en' => 'United States']],
            'subdivisions' => [
                ['iso_code' => 'CA', 'names' => ['en' => 'California']],
            ],
            'city' => ['names' => ['en' => 'Mountain View']],
            'location' => [
                'latitude' => 37.386,
                'longitude' => -122.0838,
                'time_zone' => 'America/Los_Angeles',
                'accuracy_radius' => 1000,
            ],
        ]);

        $fixture->addNetwork('1.2.3.0', 24, 'demo');
        $fixture->addNetwork('8.8.8.0', 24, 'demo');

        $binary = $fixture->build('GeoLite2-City-Test', 1710000000);

        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $binary);

        return $path;
    }

    /** @param array<string, mixed> $value */
    public function addRecord(string $key, array $value): void
    {
        $this->dataOffsets[$key] = strlen($this->dataSection);
        $this->dataSection .= $this->encode($value);
    }

    public function addNetwork(string $ip, int $prefixLength, string $dataKey): void
    {
        $packed = inet_pton($ip);

        if ($packed === false) {
            throw new \InvalidArgumentException("Bad IP [{$ip}].");
        }

        $bytes = array_values((array) unpack('C*', $packed));
        $node = 0;

        for ($i = 0; $i < $prefixLength; $i++) {
            $bit = ($bytes[$i >> 3] >> (7 - ($i % 8))) & 1;
            $side = $bit === 0 ? 'left' : 'right';

            if ($i === $prefixLength - 1) {
                $this->tree[$node][$side] = ['data', $this->dataOffsets[$dataKey]];

                return;
            }

            if ($this->tree[$node][$side][0] !== 'node') {
                $this->tree[] = ['left' => ['empty', 0], 'right' => ['empty', 0]];
                $this->tree[$node][$side] = ['node', count($this->tree) - 1];
            }

            $node = $this->tree[$node][$side][1];
        }
    }

    public function build(string $databaseType, int $buildEpoch): string
    {
        $nodeCount = count($this->tree);
        $recordSize = 32;
        $searchTree = '';

        foreach ($this->tree as $node) {
            $searchTree .= $this->packRecord($node['left'], $nodeCount);
            $searchTree .= $this->packRecord($node['right'], $nodeCount);
        }

        $metadata = $this->encode([
            'node_count' => $this->uint32($nodeCount),
            'record_size' => $this->uint16($recordSize),
            'ip_version' => $this->uint16(4),
            'database_type' => $databaseType,
            'languages' => ['en'],
            'binary_format_major_version' => $this->uint16(2),
            'binary_format_minor_version' => $this->uint16(0),
            'build_epoch' => $this->uint64($buildEpoch),
            'description' => ['en' => 'WP-24 test database'],
        ]);

        return $searchTree.str_repeat("\0", 16).$this->dataSection.self::MARKER.$metadata;
    }

    /* ================================================================ *
     |  Encoding
     * ================================================================ */

    /** @param array{0: string, 1: int} $record */
    private function packRecord(array $record, int $nodeCount): string
    {
        $value = match ($record[0]) {
            'node' => $record[1],
            'data' => $nodeCount + 16 + $record[1],
            default => $nodeCount,
        };

        return pack('N', $value);
    }

    private function encode(mixed $value): string
    {
        if ($value instanceof TypedNumber) {
            return $this->encodeTyped($value);
        }

        if (is_string($value)) {
            return $this->control(2, strlen($value)).$value;
        }

        if (is_float($value)) {
            return $this->control(3, 8).pack('E', $value);
        }

        if (is_bool($value)) {
            return $this->control(14, $value ? 1 : 0);
        }

        if (is_int($value)) {
            return $this->encodeTyped(new TypedNumber(6, $value, 4));
        }

        if (is_array($value)) {
            return array_is_list($value) ? $this->encodeArray($value) : $this->encodeMap($value);
        }

        throw new \InvalidArgumentException('Unsupported fixture value type.');
    }

    /** @param array<string, mixed> $map */
    private function encodeMap(array $map): string
    {
        $out = $this->control(7, count($map));

        foreach ($map as $key => $item) {
            $out .= $this->encode((string) $key).$this->encode($item);
        }

        return $out;
    }

    /** @param list<mixed> $items */
    private function encodeArray(array $items): string
    {
        $out = $this->control(11, count($items));

        foreach ($items as $item) {
            $out .= $this->encode($item);
        }

        return $out;
    }

    private function encodeTyped(TypedNumber $number): string
    {
        $bytes = '';
        $value = $number->value;

        for ($i = $number->width - 1; $i >= 0; $i--) {
            $bytes .= chr(($value >> ($i * 8)) & 0xFF);
        }

        // Leading zero bytes are not stored.
        $bytes = ltrim($bytes, "\0");

        return $this->control($number->type, strlen($bytes)).$bytes;
    }

    /**
     * Control byte(s): types 1–7 carry the type in the top 3 bits; types ≥ 8
     * set those bits to 0 and follow with (type - 7).
     */
    private function control(int $type, int $size): string
    {
        if ($size >= 29) {
            throw new \InvalidArgumentException('Fixture values must stay under the 29-byte short form.');
        }

        if ($type < 8) {
            return chr(($type << 5) | $size);
        }

        return chr($size).chr($type - 7);
    }

    private function uint16(int $value): TypedNumber
    {
        return new TypedNumber(5, $value, 2);
    }

    private function uint32(int $value): TypedNumber
    {
        return new TypedNumber(6, $value, 4);
    }

    private function uint64(int $value): TypedNumber
    {
        return new TypedNumber(9, $value, 8);
    }
}
