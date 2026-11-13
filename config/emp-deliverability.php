<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Deliverability centre (WP-33, spec §2.9.9 + §3.13.1 F-3.13.1-007)
|--------------------------------------------------------------------------
*/

return [

    /*
     * Sender reputation thresholds (F-2.9.9-001) as FRACTIONS of delivered
     * mail. 0 disables a level. Industry practice: keep bounces under 2%,
     * complaints under 0.1%.
     */
    'thresholds' => [
        'bounce' => [
            'warning' => (float) env('EMP_DELIV_BOUNCE_WARNING', 0.02),
            'critical' => (float) env('EMP_DELIV_BOUNCE_CRITICAL', 0.05),
        ],
        'complaint' => [
            'warning' => (float) env('EMP_DELIV_COMPLAINT_WARNING', 0.001),
            'critical' => (float) env('EMP_DELIV_COMPLAINT_CRITICAL', 0.003),
        ],
        'unsubscribe' => [
            'warning' => (float) env('EMP_DELIV_UNSUB_WARNING', 0.005),
            'critical' => (float) env('EMP_DELIV_UNSUB_CRITICAL', 0.02),
        ],
    ],

    /* Window (days) the dashboard reports on by default. */
    'window_days' => (int) env('EMP_DELIV_WINDOW_DAYS', 30),

    /*
     * Public DNSBLs consulted by DnsblChecker (F-2.9.9-003). `zones` take an
     * IPv4 address in reversed-octet form; `domain_zones` are RHSBLs that
     * take a domain name. All lookups are plain DNS — no accounts, no keys.
     */
    'dnsbl' => [
        'zones' => [
            'zen.spamhaus.org',
            'b.barracudacentral.org',
            'dnsbl.sorbs.net',
            'bl.spamcop.net',
            'psbl.surriel.com',
        ],
        'domain_zones' => [
            'dbl.spamhaus.org',
            'multi.surbl.org',
        ],
    ],

    /*
     * BIMI (F-2.9.9-002 summary + F-2.9.7-009 record sheet and validation).
     * `logo_max_bytes` is the ceiling mailbox providers apply to the SVG at
     * l=; 32 KB is the published Google/Yahoo limit.
     */
    'bimi' => [
        'selector' => env('EMP_BIMI_SELECTOR', 'default'),
        'logo_max_bytes' => (int) env('EMP_BIMI_LOGO_MAX_BYTES', 32768),
        'fetch_timeout' => (int) env('EMP_BIMI_FETCH_TIMEOUT', 10),
    ],

    /*
     * Google Postmaster Tools (F-2.9.9-005). Credentials live in the
     * `postmaster` SETTINGS group (encrypted), never here — only operational
     * knobs are config. `window_days` is how much history the read-out shows.
     */
    'postmaster' => [
        'timeout' => (int) env('EMP_POSTMASTER_TIMEOUT', 15),
        'window_days' => (int) env('EMP_POSTMASTER_WINDOW_DAYS', 30),
    ],

    /*
     * Seed list / inbox placement testing (F-2.9.9-004). `timeout_minutes` is
     * how long a seed address may stay silent before its result is recorded
     * as `missing` — long enough for greylisting, short enough to be a report.
     */
    'inbox_placement' => [
        'timeout_minutes' => (int) env('EMP_PLACEMENT_TIMEOUT_MINUTES', 120),
        'max_seeds_per_run' => (int) env('EMP_PLACEMENT_MAX_SEEDS', 200),

        /*
         * IMAP folders swept for a seed message after the inbox. Provider
         * naming differs (Gmail exposes "[Gmail]/Spam", Outlook "Junk Email"),
         * so each candidate is tried and a missing folder is simply skipped.
         */
        'spam_folders' => ['Spam', 'Junk', 'Junk Email', 'Bulk Mail', '[Gmail]/Spam'],

        /* Inbox share below which a finished run raises an alert. */
        'min_inbox_rate' => (float) env('EMP_PLACEMENT_MIN_INBOX_RATE', 0.8),
    ],

    /*
     * Sunset policy (F-2.9.9-007): dormancy windows in days and the share of
     * the confirmed base that must be dormant before a recommendation fires.
     */
    'sunset' => [
        'windows' => [90, 180, 365],
        'share_threshold' => (float) env('EMP_DELIV_SUNSET_SHARE', 0.2),
    ],

    /* Server health / error-rate dashboard (§3.13.1 F-3.13.1-007). */
    'server_health' => [
        'warning' => (float) env('EMP_SERVER_HEALTH_WARNING', 0.10),
        'critical' => (float) env('EMP_SERVER_HEALTH_CRITICAL', 0.25),
        'min_attempts' => (int) env('EMP_SERVER_HEALTH_MIN_ATTEMPTS', 20),
        'window_days' => (int) env('EMP_SERVER_HEALTH_WINDOW_DAYS', 7),
    ],

];
