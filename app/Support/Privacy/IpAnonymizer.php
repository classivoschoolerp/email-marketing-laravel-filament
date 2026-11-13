<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Services\Settings\SettingsReader;
use Throwable;

/**
 * IP anonymisation (spec §3.25.16 F-3.25.16-005 + §4.5 F-4.5-009 anonymised
 * analytics).
 *
 * When the `privacy.anonymize_ips` setting is on, the last octet of an IPv4
 * address and the last 80 bits (5 hextets) of an IPv6 address are zeroed —
 * the GA/Matomo convention: the network is still usable for coarse
 * geo/analytics, the individual host is not identifiable.
 *
 * Wired at every capture point:
 *  - tracking opens/clicks/unsubscribes (App\Http\Controllers\Public\TrackingController)
 *  - public subscribe + confirm + unsubscribe (§1.5 flows)
 *  - login logs (App\Models\LoginLog::record)
 *
 * `maybe()` is the settings-aware entry point; `anonymize()` always
 * truncates, so callers that must anonymise unconditionally can say so.
 */
final class IpAnonymizer
{
    /** Truncate only when the privacy setting is on; NULL passes through. */
    public static function maybe(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return $ip;
        }

        return self::enabled() ? self::anonymize($ip) : $ip;
    }

    /** Unconditional truncation of the host portion. */
    public static function anonymize(?string $ip): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return $ip;
        }

        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::anonymizeIpv6($ip);
        }

        // Not an IP at all (proxy junk, hostname) — never echo it back.
        return null;
    }

    /**
     * Zero the low 80 bits: 2001:db8:1:2:3:4:5:6 → 2001:db8:1:0:0:0:0:0.
     * Works from the packed form so every compressed spelling normalises.
     */
    private static function anonymizeIpv6(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        $masked = substr($packed, 0, 6).str_repeat("\0", 10);
        $result = @inet_ntop($masked);

        return $result === false ? $ip : $result;
    }

    /**
     * Reading the setting must never break a capture point: a request that
     * arrives before the settings migration has run simply records the raw IP
     * (the pre-WP-22 behaviour).
     */
    private static function enabled(): bool
    {
        try {
            return SettingsReader::privacy()->anonymizeIps();
        } catch (Throwable) {
            return false;
        }
    }
}
