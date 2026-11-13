<?php

declare(strict_types=1);

use App\Services\Verification\Providers\BouncerProvider;
use App\Services\Verification\Providers\BuiltInVerifier;
use App\Services\Verification\Providers\DebounceProvider;
use App\Services\Verification\Providers\EmailableProvider;
use App\Services\Verification\Providers\KickboxProvider;
use App\Services\Verification\Providers\LocalmailProvider;
use App\Services\Verification\Providers\NeverBounceProvider;
use App\Services\Verification\Providers\VerifyreProvider;
use App\Services\Verification\Providers\ZeroBounceProvider;

/*
|--------------------------------------------------------------------------
| Email verification module (WP-16, spec §2.9.12, D-12)
|--------------------------------------------------------------------------
| Credential model (F-2.9.12-003, documented choice): per-customer API keys
| live in customer_preferences (keys "verification.provider" and
| "verification.api_key.{provider}", managed from the customer panel);
| operator-supplied SHARED credentials are the env-driven `api_key` entries
| below and act as the fallback when a customer stored none. No dedicated
| verification_settings table.
*/

return [

    // Provider used when a customer never chose one ('built-in' needs no key).
    'default' => env('EMP_VERIFICATION_PROVIDER', BuiltInVerifier::KEY),

    /*
     * Registry map (plugin-registrable like emp-sending.drivers): plugins
     * merge a key => ['class' => ...] entry from their service provider, or
     * resolve ProviderRegistry and call register() at boot.
     */
    'providers' => [
        'built-in' => ['class' => BuiltInVerifier::class],
        'emailable' => ['class' => EmailableProvider::class, 'api_key' => env('EMP_EMAILABLE_API_KEY')],
        'kickbox' => ['class' => KickboxProvider::class, 'api_key' => env('EMP_KICKBOX_API_KEY')],
        'zerobounce' => ['class' => ZeroBounceProvider::class, 'api_key' => env('EMP_ZEROBOUNCE_API_KEY')],
        'debounce' => ['class' => DebounceProvider::class, 'api_key' => env('EMP_DEBOUNCE_API_KEY')],
        'neverbounce' => ['class' => NeverBounceProvider::class, 'api_key' => env('EMP_NEVERBOUNCE_API_KEY')],
        'bouncer' => ['class' => BouncerProvider::class, 'api_key' => env('EMP_BOUNCER_API_KEY')],
        'localmail' => [
            'class' => LocalmailProvider::class,
            'api_key' => env('EMP_LOCALMAIL_API_KEY'),
            'base_url' => env('EMP_LOCALMAIL_BASE_URL'),
        ],
        'verifyre' => [
            'class' => VerifyreProvider::class,
            'api_key' => env('EMP_VERIFYRE_API_KEY'),
            'base_url' => env('EMP_VERIFYRE_BASE_URL'),
        ],
    ],

    /*
     * Real-time verification at form-submit / API-insert (F-2.9.12-005).
     * Off by default: enabling makes every public subscribe POST call the
     * customer's provider synchronously. block_risky additionally rejects
     * `risky` verdicts (undeliverable is always rejected when enabled).
     */
    'realtime' => [
        'enabled' => env('EMP_VERIFICATION_REALTIME', false),
        'block_risky' => env('EMP_VERIFICATION_REALTIME_BLOCK_RISKY', false),
    ],

    /*
     * Campaign preflight: block sending when the target list has not been
     * verified within N days (F-2.9.12-010). 0 = disabled (D-23).
     */
    'preflight' => [
        'max_age_days' => (int) env('EMP_VERIFICATION_MAX_AGE_DAYS', 0),
    ],

    // Addresses processed per queued chunk before counters are flushed.
    'batch' => [
        'chunk' => (int) env('EMP_VERIFICATION_CHUNK', 100),
    ],

];
