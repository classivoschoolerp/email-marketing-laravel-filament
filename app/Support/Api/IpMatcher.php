<?php

declare(strict_types=1);

namespace App\Support\Api;

/**
 * Matches request IPs against allow/block list entries (single IPs or CIDR
 * ranges, IPv4 + IPv6). Used by the API IP gate (spec §3.25.7 F-3.25.7-003 and
 * §2.14.1 per-key whitelist).
 */
final class IpMatcher
{
    /**
     * @param  list<string>  $entries
     */
    public static function matches(string $ip, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (self::matchesEntry($ip, trim((string) $entry))) {
                return true;
            }
        }

        return false;
    }

    private static function matchesEntry(string $ip, string $entry): bool
    {
        if ($entry === '') {
            return false;
        }

        if (! str_contains($entry, '/')) {
            $binaryIp = @inet_pton($ip);
            $binaryEntry = @inet_pton($entry);

            return $binaryIp !== false && $binaryEntry !== false && $binaryIp === $binaryEntry;
        }

        [$subnet, $bits] = explode('/', $entry, 2);

        if (! is_numeric($bits)) {
            return false;
        }

        $binaryIp = @inet_pton($ip);
        $binarySubnet = @inet_pton(trim($subnet));

        if ($binaryIp === false || $binarySubnet === false || strlen($binaryIp) !== strlen($binarySubnet)) {
            return false;
        }

        $bits = (int) $bits;
        $maxBits = strlen($binaryIp) * 8;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0 && substr($binaryIp, 0, $fullBytes) !== substr($binarySubnet, 0, $fullBytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($binaryIp[$fullBytes]) & $mask) === (ord($binarySubnet[$fullBytes]) & $mask);
    }
}
