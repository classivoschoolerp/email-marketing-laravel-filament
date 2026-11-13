<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Delivery infrastructure module (WP-15, spec §2.9.4/§2.9.5/§2.9.7/§2.9.8)
|--------------------------------------------------------------------------
*/

return [

    'bounces' => [
        // Characters of the raw message kept in campaign_bounce_logs.message.
        'excerpt_length' => 1000,

        // Default mailbox batch size when a server has none configured.
        'default_batch' => 200,
    ],

    'domains' => [
        // Default DKIM selector for new sending domains (spec §2.9.7).
        'dkim_selector' => 'default',

        /*
         * Host whose SPF record customers must include. null = derive from
         * app.url. Rendered as "v=spf1 include:{host} ~all".
         */
        'spf_host' => env('EMP_SPF_HOST'),

        // Recommended DMARC policy for the displayed record.
        'dmarc_policy' => 'v=DMARC1; p=none;',

        /*
         * Records that must pass for a sending domain to be "verified"
         * (spec §2.9.7: DKIM TXT, SPF TXT, DMARC TXT required; MX optional).
         */
        'required_records' => ['dkim', 'spf', 'dmarc'],
    ],

    'tracking' => [
        /*
         * CNAME target customers point their tracking domains at
         * (spec §2.9.8). null = derive from app.url host.
         */
        'cname_target' => env('EMP_TRACKING_CNAME_TARGET'),
    ],

];
