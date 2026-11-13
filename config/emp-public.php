<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Email Marketing Platform — public frontend module (WP-09, spec §1.5–1.10)
|--------------------------------------------------------------------------
*/

return [

    'block_requests' => [
        /*
         * Auto-approve confirmed "never email me again" requests straight
         * into the global blacklist (spec §3.19 F-3.19-003).
         *
         * Stored in config (env-driven) rather than a Settings class:
         * WP-09 must not touch app/Settings/** (WP-05 territory, parallel
         * work) and spatie-settings classes are only discovered there.
         * WP-16 (blacklist module) migrates this into its settings group.
         */
        'auto_approve' => (bool) env('EMP_BLOCK_REQUESTS_AUTO_APPROVE', false),

        // Lifetime of the signed confirmation link (hours).
        'confirm_ttl_hours' => (int) env('EMP_BLOCK_REQUESTS_CONFIRM_TTL_HOURS', 48),
    ],

];
