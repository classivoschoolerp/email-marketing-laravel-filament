<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Laravel Horizon (spec §0.1 F-0.1-005, D-04)
|--------------------------------------------------------------------------
| Horizon needs ext-pcntl + ext-posix and a Redis queue, so it CANNOT be
| installed on the Windows development machine this platform is being built
| on (D-04). It is therefore OPTIONAL and this file ships pre-written so a
| Linux deployment only has to run:
|
|     composer require laravel/horizon
|     php artisan horizon:install   # publishes assets only
|
| Package auto-discovery then registers Horizon, this file becomes its config
| (Laravel merges config/horizon.php over the package default), and
| App\Support\Ops\HorizonSupport — wired from routes/modules/ops.php — gates
| the dashboard behind the same `logs.view` permission as the built-in queue
| monitor. Without the package nothing here is read, and the platform keeps
| using the WP-17 QueueMonitorService + WP-24 worker registry instead.
|
| Queue names mirror App\Support\Queue\Queues and docs/deploy-mysql.md.
*/

return [

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'admin/horizon'),

    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    'middleware' => ['web'],

    'waits' => [
        'redis:campaigns-send' => 300,
        'redis:transactional' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 128,

    'defaults' => [
        'transactional' => [
            'connection' => 'redis',
            'queue' => ['transactional', 'campaigns-high'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 0,
        ],
        'sending' => [
            'connection' => 'redis',
            'queue' => ['campaigns-send', 'automation'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'maxProcesses' => 20,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 300,
            'nice' => 0,
        ],
        'ingest' => [
            'connection' => 'redis',
            'queue' => ['imports', 'bounces', 'webhooks'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 6,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 900,
            'nice' => 0,
        ],
        'maintenance' => [
            'connection' => 'redis',
            'queue' => ['exports', 'maintenance'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'maxTime' => 3600,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 1800,
            'nice' => 5,
        ],
    ],

    'environments' => [
        'production' => [
            'transactional' => ['maxProcesses' => 6, 'minProcesses' => 1],
            'sending' => ['maxProcesses' => 30, 'minProcesses' => 2],
            'ingest' => ['maxProcesses' => 8, 'minProcesses' => 1],
            'maintenance' => ['maxProcesses' => 4, 'minProcesses' => 1],
        ],
        'staging' => [
            'transactional' => ['maxProcesses' => 2, 'minProcesses' => 1],
            'sending' => ['maxProcesses' => 4, 'minProcesses' => 1],
            'ingest' => ['maxProcesses' => 2, 'minProcesses' => 1],
            'maintenance' => ['maxProcesses' => 1, 'minProcesses' => 1],
        ],
        'local' => [
            'transactional' => ['maxProcesses' => 1],
            'sending' => ['maxProcesses' => 1],
            'ingest' => ['maxProcesses' => 1],
            'maintenance' => ['maxProcesses' => 1],
        ],
    ],

];
