<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IP / domain warmup module (WP-33, spec §2.9.3)
|--------------------------------------------------------------------------
| Fully self-hosted (F-2.9.3-013): no external warmup service, no API key,
| no outbound call anywhere in this module.
*/

return [
    /*
     * Seconds a ServerPicker reservation may hold a warmup slot before it is
     * treated as abandoned. A reservation normally settles within one send
     * attempt; this only covers picks that never reached the driver (a
     * spam-blocked message, a worker killed mid-job).
     */
    'reservation_ttl' => (int) env('EMP_WARMUP_RESERVATION_TTL', 900),

    /* Defaults offered by the plan form. */
    'defaults' => [
        'days' => 30,
        'start_volume' => 50,
        'increment_value' => 50,
        'max_daily_volume' => 0,
    ],
];
