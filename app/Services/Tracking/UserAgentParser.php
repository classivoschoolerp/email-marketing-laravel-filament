<?php

declare(strict_types=1);

namespace App\Services\Tracking;

/**
 * Small regex-based user-agent parser for tracking logs (spec §1.7
 * F-1.7-006): device (desktop/mobile/tablet), OS, browser and — where
 * detectable — the email client or image proxy that fetched the resource.
 * Deliberately dependency-free (no composer package); covers the major
 * families, everything else stays null. Bot/prefetch FILTERING (Apple MPP,
 * Gmail proxy heuristics) is P2 (F-1.7-007) and intentionally not done here —
 * this class only labels, it never drops events.
 */
class UserAgentParser
{
    public function parse(?string $userAgent): ParsedUserAgent
    {
        $ua = trim((string) $userAgent);

        if ($ua === '') {
            return new ParsedUserAgent(null, null, null, null);
        }

        return new ParsedUserAgent(
            $this->device($ua),
            $this->os($ua),
            $this->browser($ua),
            $this->client($ua),
        );
    }

    private function device(string $ua): ?string
    {
        if (preg_match('/iPad|Tablet|Kindle|Silk|PlayBook|Nexus (?:7|9|10)/i', $ua) === 1) {
            return 'tablet';
        }

        if (preg_match('/Mobile|iPhone|iPod|Windows Phone|BlackBerry/i', $ua) === 1
            || (str_contains($ua, 'Android') && preg_match('/Mobile/i', $ua) === 1)) {
            return 'mobile';
        }

        if (preg_match('/Android/i', $ua) === 1) {
            // Android without "Mobile" is a tablet per Google's UA guidance.
            return 'tablet';
        }

        if (preg_match('/Windows|Macintosh|Mac OS X|X11|Linux|CrOS/i', $ua) === 1) {
            return 'desktop';
        }

        return null;
    }

    private function os(string $ua): ?string
    {
        return match (true) {
            preg_match('/Windows Phone/i', $ua) === 1 => 'Windows Phone',
            preg_match('/Windows NT|Win64|Win32/i', $ua) === 1 => 'Windows',
            preg_match('/iPhone|iPad|iPod/i', $ua) === 1 => 'iOS',
            preg_match('/Android/i', $ua) === 1 => 'Android',
            preg_match('/Mac OS X|Macintosh/i', $ua) === 1 => 'macOS',
            preg_match('/CrOS/i', $ua) === 1 => 'ChromeOS',
            preg_match('/Linux|X11/i', $ua) === 1 => 'Linux',
            default => null,
        };
    }

    private function browser(string $ua): ?string
    {
        return match (true) {
            preg_match('/Edg(?:e|A|iOS)?\//i', $ua) === 1 => 'Edge',
            preg_match('/OPR\/|Opera/i', $ua) === 1 => 'Opera',
            preg_match('/SamsungBrowser\//i', $ua) === 1 => 'Samsung Internet',
            preg_match('/CriOS\//i', $ua) === 1 => 'Chrome',
            preg_match('/FxiOS\//i', $ua) === 1 => 'Firefox',
            preg_match('/Chrome\//i', $ua) === 1 => 'Chrome',
            preg_match('/Firefox\//i', $ua) === 1 => 'Firefox',
            // Safari must come after Chrome/CriOS — Chrome UAs contain "Safari".
            preg_match('/Safari\//i', $ua) === 1 => 'Safari',
            preg_match('/MSIE |Trident\//i', $ua) === 1 => 'Internet Explorer',
            default => null,
        };
    }

    /** Email client / image proxy detection (best effort). */
    private function client(string $ua): ?string
    {
        return match (true) {
            // Gmail rewrites images through its proxy — the open "device" is
            // Google's fetcher, not the user's (F-1.7-007 filtering is P2).
            preg_match('/GoogleImageProxy/i', $ua) === 1 => 'Gmail',
            preg_match('/YahooMailProxy|YahooCacheSystem/i', $ua) === 1 => 'Yahoo Mail',
            preg_match('/Thunderbird\//i', $ua) === 1 => 'Thunderbird',
            preg_match('/Microsoft Outlook|MSOffice|Microsoft Office/i', $ua) === 1 => 'Outlook',
            preg_match('/Outlook-iOS|Outlook-Android/i', $ua) === 1 => 'Outlook Mobile',
            // Apple Mail privacy proxy + desktop/iOS Mail app.
            preg_match('/AppleMailPrivacyProtection/i', $ua) === 1 => 'Apple Mail',
            preg_match('/Mac OS X.*AppleWebKit(?!.*(?:Chrome|CriOS|Safari\/))/i', $ua) === 1 => 'Apple Mail',
            default => null,
        };
    }
}
