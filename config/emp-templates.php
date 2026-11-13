<?php

declare(strict_types=1);

use App\Services\Templates\Builders\BasicDriver;
use App\Services\Templates\Builders\GrapesJsDriver;
use App\Services\Templates\Builders\RawHtmlDriver;
use App\Services\Templates\Builders\RichTextDriver;
use App\Services\Templates\Builders\StripoDriver;
use App\Services\Templates\Builders\UnlayerDriver;
use App\Services\Templates\Stock\PexelsProvider;
use App\Services\Templates\Stock\UnsplashProvider;

/*
|--------------------------------------------------------------------------
| Templates module configuration (WP-10, spec §2.5 / §3.14)
|--------------------------------------------------------------------------
| This file is owned by the templates module. The operator can override the
| default builder at runtime via the template_settings key-value store
| (Admin → Templates → Builder defaults); values here are the fallbacks.
*/

return [

    /*
     * Fallback default builder when neither the customer preference
     * (customer_preferences key "templates.default_builder") nor the operator
     * override (template_settings key "default_builder") is set.
     */
    'default_builder' => env('EMP_TEMPLATES_DEFAULT_BUILDER', 'grapesjs'),

    /*
     * Builder driver registry (spec §2.5.3, docs/ARCHITECTURE.md rule 2). New vendors add
     * a class implementing BuilderDriver here — no core change required.
     */
    'builders' => [
        GrapesJsDriver::class,
        // WP-27B (F-2.5.3-001): the simple built-in block builder — no
        // bundled editor library, no vendor account, always available.
        BasicDriver::class,
        RawHtmlDriver::class,
        RichTextDriver::class,
        StripoDriver::class,
        UnlayerDriver::class,
    ],

    // Stripo plugin credentials (F-2.5.3-003). Without both values the driver
    // reports itself unavailable and is hidden from builder choices.
    'stripo' => [
        'plugin_id' => env('EMP_STRIPO_PLUGIN_ID'),
        'secret_key' => env('EMP_STRIPO_SECRET_KEY'),

        /*
         * WP-26 (F-2.5.3-003). Stripo's documented plugin flow:
         *  1. the SERVER exchanges pluginId + secretKey (+ a stable userId
         *     for the tenant) for a short-lived token at `auth_url`;
         *  2. the browser loads `script_url` and calls Stripo.init(...) with
         *     a getAuthToken callback that hands over that token;
         *  3. StripoApi.compileEmail()/getTemplate() returns the final HTML,
         *     which we POST to the existing builder autosave endpoint.
         * The secret key NEVER reaches the browser.
         */
        'auth_url' => env('EMP_STRIPO_AUTH_URL', 'https://plugins.stripo.email/api/v1/auth'),
        'script_url' => env('EMP_STRIPO_SCRIPT_URL', 'https://plugins.stripo.email/static/latest/stripo.js'),
        // Seconds a fetched auth token is reused for (Stripo tokens are short-lived).
        'token_ttl' => (int) env('EMP_STRIPO_TOKEN_TTL', 600),
    ],

    // Unlayer project id (F-2.5.3-004, P2).
    'unlayer' => [
        'project_id' => env('EMP_UNLAYER_PROJECT_ID'),
    ],

    /*
     * Revision history (F-2.5.4-008): a revision row is written on every
     * content save; only the most recent N are kept.
     */
    'revisions_keep' => (int) env('EMP_TEMPLATES_REVISIONS_KEEP', 20),

    // Builder autosave debounce, milliseconds (F-2.5.4-008).
    'autosave_debounce_ms' => (int) env('EMP_TEMPLATES_AUTOSAVE_MS', 2000),

    /*
     * Unsubscribe-tag check on save (F-2.5.5-006). "warn" flags the template
     * and shows a warning; "block" additionally rejects the save. Which tags
     * satisfy the check is driven by CampaignSettings::KNOWN_TEMPLATE_TAGS
     * (UNSUBSCRIBE_URL / DIRECT_UNSUBSCRIBE_URL).
     */
    'unsubscribe_check' => env('EMP_TEMPLATES_UNSUBSCRIBE_CHECK', 'warn'),

    /*
     * ZIP import/export (F-2.5.5-001/-007, F-3.14-003).
     * Uploads up to sync_threshold_mb are processed synchronously in the
     * request; bulk gallery packs are always queued (Queues::IMPORTS).
     */
    'zip' => [
        'max_size_mb' => (int) env('EMP_TEMPLATES_ZIP_MAX_MB', 20),
        'sync_threshold_mb' => (int) env('EMP_TEMPLATES_ZIP_SYNC_MB', 20),
        'image_extensions' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'],
    ],

    // Builder image uploads (kilobytes).
    'assets' => [
        'max_kb' => (int) env('EMP_TEMPLATES_ASSET_MAX_KB', 5120),
        'extensions' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'],
    ],

    /*
     * Stock image libraries (F-2.5.4-010, WP-42). Driver registry, same shape
     * as `builders` above: a new library is a class implementing
     * App\Services\Templates\Stock\StockImageProvider listed here.
     *
     * API keys are NOT read from here in normal operation — they live
     * encrypted in the template_settings store
     * (App\Services\Templates\Stock\StockImageCredentials). The env values
     * below are only a fallback for deployments that configure by .env.
     *
     * A library with no key is never offered: the picker is simply absent.
     */
    'stock' => [
        'providers' => [
            UnsplashProvider::class,
            PexelsProvider::class,
        ],

        'unsplash' => ['api_key' => env('EMP_UNSPLASH_ACCESS_KEY')],
        'pexels' => ['api_key' => env('EMP_PEXELS_API_KEY')],

        // Results per page in the picker grid.
        'per_page' => (int) env('EMP_TEMPLATES_STOCK_PER_PAGE', 24),
        // Seconds for both the search call and the image download.
        'timeout' => (int) env('EMP_TEMPLATES_STOCK_TIMEOUT', 10),
        // Ceiling on a downloaded stock image, bytes.
        'max_bytes' => (int) env('EMP_TEMPLATES_STOCK_MAX_BYTES', 8_388_608),
    ],

    /*
     * Client-side image editor (F-2.5.4-009, WP-42). Crop/resize/filters/text
     * overlay all happen in the browser on a <canvas>; the server only stores
     * the resulting PNG/JPEG in the tenant library. This ceiling is on that
     * decoded upload, bytes.
     */
    'image_editor' => [
        'max_bytes' => (int) env('EMP_TEMPLATES_IMAGE_EDITOR_MAX_BYTES', 8_388_608),
    ],

    // Import from URL (F-2.5.5-002).
    'import_url' => [
        'timeout' => (int) env('EMP_TEMPLATES_IMPORT_URL_TIMEOUT', 15),
        'max_bytes' => (int) env('EMP_TEMPLATES_IMPORT_URL_MAX_BYTES', 2_097_152),
    ],

    // Hosts allowed for the custom-fonts URL field (F-2.5.4-012).
    'google_fonts_hosts' => ['fonts.googleapis.com', 'fonts.bunny.net'],

    // Max recipients per send-test action (F-2.5.5-004).
    'send_test_max_recipients' => (int) env('EMP_TEMPLATES_SEND_TEST_MAX', 10),

    /*
     * Sample subscriber data used for previews and test sends
     * (F-2.5.5-005). Keys are CampaignSettings::KNOWN_TEMPLATE_TAGS.
     */
    'sample_data' => [
        'SUBSCRIBER_EMAIL' => 'jane.doe@example.com',
        'SUBSCRIBER_NAME' => 'Jane Doe',
        'SUBSCRIBER_FIRST_NAME' => 'Jane',
        'SUBSCRIBER_LAST_NAME' => 'Doe',
        'SUBSCRIBER_UID' => 'sub-0000-sample',
        'LIST_NAME' => 'Newsletter',
        'LIST_UID' => 'list-0000-sample',
        'CAMPAIGN_NAME' => 'Sample campaign',
        'CAMPAIGN_SUBJECT' => 'Sample subject',
        'CAMPAIGN_UID' => 'camp-0000-sample',
        'UNSUBSCRIBE_URL' => 'https://example.com/unsubscribe/sample',
        'DIRECT_UNSUBSCRIBE_URL' => 'https://example.com/unsubscribe/sample/direct',
        'UPDATE_PROFILE_URL' => 'https://example.com/profile/sample',
        'WEB_VERSION_URL' => 'https://example.com/web-version/sample',
        'FORWARD_URL' => 'https://example.com/forward/sample',
        'COMPANY_NAME' => 'Acme Inc.',
        'COMPANY_FULL_ADDRESS' => '1 Acme Way, Springfield, USA',
        // CURRENT_YEAR / CURRENT_MONTH / CURRENT_DAY are computed at render time.
    ],
];
