<?php

declare(strict_types=1);

/*
 * Campaigns module configuration (WP-11/12, spec §2.3).
 */
return [

    // GrapesJS autosave debounce for the campaign content builder page.
    'autosave_debounce_ms' => env('EMP_CAMPAIGN_AUTOSAVE_MS', 2000),

    // Spintax preview: number of sample permutations returned (F-2.3.3-022).
    'spintax_preview_samples' => env('EMP_SPINTAX_PREVIEW_SAMPLES', 5),

    // Live progress-bar poll interval on the campaigns index (F-2.3.1-003).
    'index_poll_seconds' => env('EMP_CAMPAIGNS_INDEX_POLL', 15),

    // Max size accepted for a content ZIP upload (reuses template importer).
    'zip_max_size_mb' => env('EMP_CAMPAIGN_ZIP_MAX_MB', 20),

    /*
     * Inbox rendering preview (§2.3.5 F-2.3.5-006, WP-46). ONLY the HTTP
     * timeout lives here — the vendor CREDENTIALS are in the encrypted
     * `inbox_preview` settings group (App\Settings\InboxPreviewSettings),
     * never in a config file that ends up in version control and in
     * `config:cache` output.
     */
    'inbox_preview' => [
        'timeout_seconds' => (int) env('EMP_INBOX_PREVIEW_TIMEOUT', 30),
    ],

    /*
     * Pre-flight checklist gates (§2.3.5 F-2.3.5-008).
     *
     * require_authenticated_from_domain — when true, a campaign may only be
     * scheduled if the domain of its From address is covered by a WP-15
     * sending domain in status "verified" (DKIM + SPF + DMARC, D-66), either
     * the tenant's own row or an operator-global one. Subdomains of a
     * verified organisational domain count (relaxed alignment).
     *
     * DEFAULT FALSE, matching the two other gated pre-flight checks
     * (emp-verification.preflight.max_age_days = 0 and PrivacySettings
     * canspam_enforce_address = false): real verification needs public DNS
     * (D-16), so defaulting to on would block scheduling on every install
     * that has not published its records yet. The check also skips itself
     * honestly when the install has no sending domain at all.
     */
    'preflight' => [
        'require_authenticated_from_domain' => (bool) env('EMP_CAMPAIGNS_REQUIRE_AUTH_FROM_DOMAIN', false),
    ],

    /*
     * WP-35 — Phase 2.
     */

    // §2.3.3 F-2.3.3-019: how many content versions to keep per campaign.
    // Falls back to emp-templates.revisions_keep so both builders age out at
    // the same depth unless an operator deliberately splits them.
    'revisions_keep' => env('EMP_CAMPAIGN_REVISIONS_KEEP', 20),

    // §2.3.3 F-2.3.3-011/024: how long a fetched RSS feed is cached. An email
    // bakes the items in at build time, so a short cache only saves the
    // publisher from being hit once per preview render.
    'rss_cache_seconds' => env('EMP_CAMPAIGN_RSS_CACHE_SECONDS', 900),
];
