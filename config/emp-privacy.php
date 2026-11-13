<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Email Marketing Platform — compliance & privacy module (WP-22)
|--------------------------------------------------------------------------
| Spec §4.5 + §3.25.16. Operator-facing switches live in the `privacy` and
| `security` settings groups; only mechanical values that an operator never
| needs to see stay here.
|
| SEE ALSO config/emp-consent.php — §4.5 F-4.5-003 CASL and the other
| country-specific consent regimes live in their own file because they are a
| reference TABLE (country => law => obligations) an operator does edit, not
| the mechanical knobs this file holds.
*/

return [

    'erasure' => [
        // Lifetime of the signed "confirm my erasure request" link (hours).
        'confirm_ttl_hours' => (int) env('EMP_ERASURE_CONFIRM_TTL_HOURS', 48),
    ],

    'export' => [
        // Lifetime of the signed subscriber self-service download link.
        'link_ttl_minutes' => (int) env('EMP_PRIVACY_EXPORT_TTL_MINUTES', 60),
    ],

    'cookies' => [
        // Name + lifetime of the cookie storing the visitor's banner choice.
        'cookie_name' => 'emp_cookie_consent',
        'cookie_days' => (int) env('EMP_COOKIE_CONSENT_DAYS', 365),
    ],

];
