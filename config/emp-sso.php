<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SSO & external authentication (spec §6.7 — WP-38 + WP-46)
|--------------------------------------------------------------------------
| WP-38 shipped the P2 rows: the one-time login token API (F-6.7-001) and
| social login (F-6.7-003).
|
| WP-46 added F-6.7-002 — a configurable OIDC identity provider (discovery +
| full id_token validation) and a SAML 2.0 service provider. Their operator
| configuration lives in the `enterprise_sso` SETTINGS group, not here; the
| keys below are the protocol-level knobs an operator has no reason to touch.
*/

return [

    'login_token' => [
        /*
         * Seconds a freshly issued one-time token stays usable. Short by
         * design: the token is a bearer credential that logs somebody in
         * with no password and no second factor, so its whole safety model
         * is "single use, and gone in a minute".
         */
        'ttl_seconds' => (int) env('EMP_SSO_TOKEN_TTL', 60),

        /* Upper bound an API caller may request via the `ttl` parameter. */
        'max_ttl_seconds' => (int) env('EMP_SSO_TOKEN_MAX_TTL', 300),

        /* Rate limit on the redemption endpoint, "attempts,minutes". */
        'redeem_throttle' => env('EMP_SSO_REDEEM_THROTTLE', '10,1'),

        /* Rate limit on the issuing API endpoint. */
        'issue_throttle' => env('EMP_SSO_ISSUE_THROTTLE', '30,1'),

        /*
         * Consumed/expired rows are pruned after this many days. They are
         * kept (not deleted on use) so a replay attempt is auditable.
         */
        'retention_days' => (int) env('EMP_SSO_TOKEN_RETENTION_DAYS', 30),
    ],

    'social' => [
        /*
         * Driver registry keys (spec §2.9.2 / §6.1 pattern). A new identity
         * provider registers an adapter — no core change.
         */
        'drivers' => ['google', 'microsoft', 'github', 'oidc'],

        'google' => [
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scopes' => ['openid', 'email', 'profile'],
        ],

        'microsoft' => [
            'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo',
            'scopes' => ['openid', 'email', 'profile'],
        ],

        /*
         * GitHub (spec §1.2 F-1.2-011). GitHub is OAuth2 but not OIDC: no
         * `sub`, no `email_verified`, and the token endpoint answers in form
         * encoding unless asked for JSON — see GithubAuthProvider.
         *
         * Its credentials live HERE rather than in App\Settings\SsoSettings,
         * which is frozen for this work package. Everything else about the
         * provider is identical to its siblings, and SsoSettingsReader
         * prefers a settings property the moment one exists, so moving the
         * three keys into the settings UI later is additive.
         */
        'github' => [
            'authorize_url' => 'https://github.com/login/oauth/authorize',
            'token_url' => 'https://github.com/login/oauth/access_token',
            'userinfo_url' => 'https://api.github.com/user',
            'emails_url' => 'https://api.github.com/user/emails',
            'scopes' => ['read:user', 'user:email'],
            'enabled' => (bool) env('EMP_SSO_GITHUB_ENABLED', false),
            'client_id' => env('EMP_SSO_GITHUB_CLIENT_ID'),
            'client_secret' => env('EMP_SSO_GITHUB_CLIENT_SECRET'),
        ],

        /*
         * The configurable OIDC IdP (F-6.7-002, WP-46). No URLs here on
         * purpose: they come from the issuer's discovery document at runtime,
         * which is the whole point — see App\Services\Sso\Oidc\OidcDiscovery.
         * Credentials live in the `enterprise_sso` settings group.
         */
        'oidc' => [
            'scopes' => ['openid', 'email', 'profile'],
        ],

        /* Rate limit on the redirect + callback endpoints, "attempts,minutes". */
        'throttle' => env('EMP_SOCIAL_THROTTLE', '20,1'),
    ],

    /*
     * SAML protocol knobs (F-6.7-002, WP-46). Operator configuration — the
     * IdP entity id, SSO URL, certificate and attribute mapping — lives in the
     * `enterprise_sso` settings group, not here.
     */
    'saml' => [
        /*
         * Odds (1 in N) that a successful assertion also prunes expired rows
         * from `sso_assertion_replays`. Once an assertion's own validity
         * window has passed it is refused on its own terms, so the row is pure
         * growth. 0 disables the lottery — do that only if you prune the table
         * some other way. Same shape as the §3.25.7 api_request_logs prune.
         */
        'prune_lottery' => (int) env('EMP_SAML_PRUNE_LOTTERY', 100),
    ],

    /*
     * OIDC protocol knobs (F-6.7-002, WP-46).
     *
     * The discovery document and the JWKS are cached (Laravel Cache, D-04) so
     * a login does not open two extra round trips to the IdP. A key rotation
     * becomes visible within the JWKS TTL; shorten it if your IdP rotates
     * aggressively, but note that a shorter TTL turns a stream of forged
     * tokens into more traffic at the IdP, which is why nothing re-fetches on
     * a verification failure.
     */
    'oidc' => [
        'discovery_ttl_seconds' => (int) env('EMP_OIDC_DISCOVERY_TTL', 3600),
        'jwks_ttl_seconds' => (int) env('EMP_OIDC_JWKS_TTL', 3600),
        'timeout_seconds' => (int) env('EMP_OIDC_TIMEOUT', 15),

        /* Tolerated clock difference on id_token `exp` / `iat`, in seconds. */
        'clock_skew_seconds' => (int) env('EMP_OIDC_CLOCK_SKEW', 60),
    ],

];
