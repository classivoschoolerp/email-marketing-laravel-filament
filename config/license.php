<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| License validation (spec §3.25.12, DECISIONS D-09)
|--------------------------------------------------------------------------
| No licensing backend is specified for the platform. The validation
| endpoint is configurable; when none is configured the installation runs
| in valid self-hosted mode. The optional public key enables verification
| of signed license-server responses.
*/

return [

    // POST endpoint the license key is validated against. Empty = self-hosted.
    'endpoint' => env('EMP_LICENSE_ENDPOINT'),

    // PEM public key used to verify the license server's response signature.
    // Empty = signature verification skipped (stub mode, D-09).
    'public_key' => env('EMP_LICENSE_PUBLIC_KEY'),

    // HTTP timeout (seconds) for license server calls.
    'timeout' => (int) env('EMP_LICENSE_TIMEOUT', 10),
];
