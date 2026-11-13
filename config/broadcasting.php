<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Broadcasting (spec §0.1 F-0.1-007 — Reverb or Pusher)
|--------------------------------------------------------------------------
| Real-time campaign progress bars and live stats. The default connection is
| `log` in development and `null` under test, so nothing here is required for
| the platform to run: App\Events\CampaignProgressUpdated implements
| ShouldBroadcast, and Laravel simply drops the payload when no driver is
| configured.
|
| To go live with Reverb (self-hosted, no vendor account):
|     composer require laravel/reverb
|     php artisan reverb:install
|     BROADCAST_CONNECTION=reverb
|     php artisan reverb:start
|
| Or with Pusher: BROADCAST_CONNECTION=pusher plus the PUSHER_* keys.
| docs/tech-stack.md documents both, including the customer-panel listener.
*/

return [

    // `?:` not a default argument: BROADCAST_CONNECTION=null in an .env is
    // cast to PHP null by Laravel, which would leave no driver name at all.
    'default' => env('BROADCAST_CONNECTION') ?: 'null',

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options for the HTTP publish call.
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => (int) env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('BROADCAST_REDIS_CONNECTION', 'default'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
