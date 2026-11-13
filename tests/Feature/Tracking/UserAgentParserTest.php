<?php

declare(strict_types=1);

use App\Services\Tracking\UserAgentParser;

// F-1.7-006: the regex parser covers the major device/OS/browser/client
// families without a composer dependency.
it('parses major user agents into device, os, browser and client', function (
    ?string $ua,
    ?string $device,
    ?string $os,
    ?string $browser,
    ?string $client,
): void {
    $parsed = (new UserAgentParser)->parse($ua);

    expect($parsed->device)->toBe($device)
        ->and($parsed->os)->toBe($os)
        ->and($parsed->browser)->toBe($browser)
        ->and($parsed->client)->toBe($client);
})->with([
    'Windows Chrome desktop' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'desktop', 'Windows', 'Chrome', null,
    ],
    'macOS Firefox desktop' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:126.0) Gecko/20100101 Firefox/126.0',
        'desktop', 'macOS', 'Firefox', null,
    ],
    'macOS Safari desktop' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        'desktop', 'macOS', 'Safari', null,
    ],
    'Windows Edge' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.2478.97',
        'desktop', 'Windows', 'Edge', null,
    ],
    'iPhone Safari mobile' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
        'mobile', 'iOS', 'Safari', null,
    ],
    'Android Chrome mobile' => [
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.6367.82 Mobile Safari/537.36',
        'mobile', 'Android', 'Chrome', null,
    ],
    'iPad tablet' => [
        'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
        'tablet', 'iOS', 'Safari', null,
    ],
    'Android tablet (no Mobile token)' => [
        'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'tablet', 'Android', 'Chrome', null,
    ],
    'Gmail image proxy' => [
        'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)',
        'desktop', 'Windows', 'Firefox', 'Gmail',
    ],
    'Outlook desktop' => [
        'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 10.0; Microsoft Outlook 16.0.9126; ms-office)',
        'desktop', 'Windows', 'Internet Explorer', 'Outlook',
    ],
    'Thunderbird' => [
        'Mozilla/5.0 (X11; Linux x86_64; rv:115.0) Gecko/20100101 Thunderbird/115.10.1',
        'desktop', 'Linux', null, 'Thunderbird',
    ],
    'Apple Mail privacy proxy' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) AppleMailPrivacyProtection',
        'desktop', 'macOS', null, 'Apple Mail',
    ],
    'Empty UA' => [null, null, null, null, null],
]);
