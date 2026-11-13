<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Email Marketing Platform — module configuration (WP-02, spec §0.3/§1.2–1.4/§2.15)
|--------------------------------------------------------------------------
| Values here act as the settings stubs until the admin Settings UI (WP-04)
| moves them into the database-backed settings store.
*/

return [

    /*
     * CANONICAL CORE VERSION (WP-36, D-95).
     *
     * This is the single source of truth for "what version of the platform is
     * this?" — plugin `min-core-version` / `max-core-version` constraints are
     * resolved against it (spec §6.1), and §6.2's upgrade manager will read
     * the same key. It deliberately does NOT live in composer.json: the
     * Laravel skeleton ships no `version` field, composer.json is absent from
     * production artifacts that deploy `vendor/` pre-built, and reading it at
     * runtime would mean parsing JSON on every compatibility check.
     * Semantic versioning, `major.minor.patch`.
     */
    'core_version' => env('EMP_CORE_VERSION', '1.0.0'),

    'registration' => [
        // Master switch for public self-registration (spec §1.2).
        'enabled' => env('EMP_REGISTRATION_ENABLED', true),

        // Admin approval mode: confirmed accounts wait for manual approval (F-1.2-006).
        'require_approval' => env('EMP_REGISTRATION_REQUIRE_APPROVAL', false),

        /*
         * F-1.2-008 (WP-26): show an OPTIONAL price-plan picker on the
         * registration form. Visible, non-credit-pack plans only. Once the
         * email is confirmed a zero-total plan is applied instantly and a
         * paid plan sends the customer straight into checkout.
         */
        'plan_selection' => env('EMP_REGISTRATION_PLAN_SELECTION', false),

        // Email confirmation token flow (F-1.2-005).
        'confirm_ttl_hours' => (int) env('EMP_REGISTRATION_CONFIRM_TTL_HOURS', 48),
        'resend_throttle' => '3,1', // Laravel throttle middleware spec: 3 attempts / 1 minute

        // Honeypot field name rendered as a visually hidden input (spam trap).
        'honeypot_field' => env('EMP_REGISTRATION_HONEYPOT', 'website_url'),

        /*
         * Admin-defined extra registration fields (F-1.2-002). Each entry:
         * ['key' => 'how_heard', 'label' => 'How did you hear about us?',
         *  'type' => 'text'|'select', 'required' => bool, 'options' => [...] (select only)]
         * The admin CRUD UI for these lands with the settings work package; the
         * public form renders/validates/stores whatever is configured here.
         */
        'custom_fields' => [],

        // Disposable-email domain blocklist (F-1.2-009).
        'disposable_domains_file' => env(
            'EMP_DISPOSABLE_DOMAINS_FILE',
            resource_path('data/disposable-email-domains.txt')
        ),
        'disposable_domains' => [], // extra domains on top of the file

        // Terms & privacy links shown next to the consent checkbox (F-1.2-004).
        // CMS pages arrive in a later work package; the URLs are configurable now.
        'terms_url' => env('EMP_TERMS_URL', '/page/terms-of-service'),
        'privacy_url' => env('EMP_PRIVACY_URL', '/page/privacy-policy'),

        /*
         * Referral code capture (F-1.2-010). A code arriving as `?ref=CODE`
         * on ANY page in the web group is parked on the session and written
         * onto whatever account that visit eventually creates — through the
         * form or through a social sign-up. `max_length` mirrors the
         * `customers.referral_code` column; anything longer is not a
         * referral code and is ignored rather than truncated.
         */
        'referral' => [
            'query_param' => env('EMP_REFERRAL_QUERY_PARAM', 'ref'),
            'max_length' => 64,
        ],
    ],

    'captcha' => [
        // Drivers: "null", "recaptcha-v2", "recaptcha-v3", "hcaptcha" (F-1.2-003).
        'driver' => env('EMP_CAPTCHA_DRIVER', 'null'),
        'site_key' => env('EMP_CAPTCHA_SITE_KEY'),
        'secret_key' => env('EMP_CAPTCHA_SECRET_KEY'),
        // reCAPTCHA v3 only: minimum score to accept.
        'min_score' => (float) env('EMP_CAPTCHA_MIN_SCORE', 0.5),
    ],

    'login' => [
        // Failed-attempt throttling + IP lockout (F-1.3-004).
        'max_attempts' => (int) env('EMP_LOGIN_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('EMP_LOGIN_DECAY_SECONDS', 60),
        'ip_max_attempts' => (int) env('EMP_LOGIN_IP_MAX_ATTEMPTS', 20),
        'ip_decay_seconds' => (int) env('EMP_LOGIN_IP_DECAY_SECONDS', 600),
    ],

    'twofa' => [
        'recovery_codes' => 10,
        // TOTP verification window (30s steps either side).
        'window' => 1,
    ],

    'email_change' => [
        // Re-verification link lifetime for email changes (F-2.15.1-005).
        'ttl_hours' => (int) env('EMP_EMAIL_CHANGE_TTL_HOURS', 48),
    ],

    'profile' => [
        'locales' => ['en' => 'English'],
        'date_formats' => ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'M j, Y'],
    ],

    'roles' => [
        // Canonical system role names (locked, seeded — spec §0.3 / D-06).
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'support' => 'Support',
        'read_only' => 'Read-only',
        'customer_owner' => 'Customer Owner',
    ],

    'impersonation' => [
        // Login-as / impersonation flag + permission definition (F-0.3-012).
        // The admin UI + audit-trail recording ship with WP-03.
        'enabled' => env('EMP_IMPERSONATION_ENABLED', true),
        'permission' => 'customers.impersonate',
    ],

    /*
     * Customer-group quota matrix defaults (F-0.3-005). A null value means
     * unlimited; per-group overrides live in customer_group_limits.
     */
    'quotas' => [
        'defaults' => [
            'max_lists' => 100,
            'max_subscribers' => 100_000,
            'max_campaigns' => 1_000,
            'max_delivery_servers' => 5,
            'sends_per_hour' => 10_000,
            'sends_per_day' => 100_000,
            'sends_per_month' => 1_000_000,
        ],
    ],

    /*
     * Customer-group feature-permission matrix defaults (F-0.3-004). Features
     * not listed fall back to "default_allowed"; per-group overrides live in
     * customer_group_permissions.
     */
    'group_permissions' => [
        'default_allowed' => true,
        'defaults' => [
            // Keys are read through config()/Arr::get, so a dotted permission
            // key must be written NESTED (['api' => ['admin' => …]]) — a flat
            // 'api.admin' entry would never be found.
            //
            // api.admin (§2.14.1 F-2.14.1-004 + Part 5 F-Endpoints-022) is the
            // one permission that must DENY by default: it authorises the
            // `admin` API-key scope, which unlocks platform-wide /customers
            // CRUD. Only a platform admin may switch it on, per group or per
            // customer (D-42/D-43).
            'api' => [
                'admin' => false,
            ],

            // branding.agency (§4.7 F-4.7-004, WP-46) also denies by default:
            // it lets a tenant replace the product's name, logo and colours
            // for its whole team, which is a commercial decision an operator
            // makes per group or per customer — never something a new signup
            // inherits.
            'branding' => [
                'agency' => false,
            ],
        ],
    ],
];
