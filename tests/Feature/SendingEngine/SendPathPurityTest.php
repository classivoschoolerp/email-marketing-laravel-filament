<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * §H / docs/ARCHITECTURE.md rule 1 — the sending engine lives OUTSIDE Filament: no
 * Filament, Livewire or HTTP-controller types may enter the send path.
 */

it('keeps the send path free of Filament, Livewire and HTTP controllers', function (): void {
    $paths = [
        app_path('Sending'),
        app_path('Jobs/Sending'),
        app_path('Services/Content'),
        app_path('Services/Stats'),
        app_path('Services/Cron'),
        app_path('Console/Commands'),
    ];

    $forbidden = [
        'use Filament\\',
        'use App\\Filament',
        'use Livewire\\',
        'use App\\Http\\Controllers',
        'use Illuminate\\Http\\Request',
    ];

    $offenders = [];

    foreach ($paths as $path) {
        foreach (File::allFiles($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = File::get($file->getPathname());

            foreach ($forbidden as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = $file->getPathname().' imports '.$needle;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the send jobs queued on the dedicated queues (docs/ARCHITECTURE.md rule 5)', function (): void {
    expect((new \App\Jobs\Sending\SendToSubscriberJob(1, 1, 'c', 's'))->queue)
        ->toBe(\App\Support\Queue\Queues::CAMPAIGNS_SEND)
        ->and((new \App\Jobs\Sending\StartCampaignSendJob(1))->queue)
        ->toBe(\App\Support\Queue\Queues::CAMPAIGNS_HIGH)
        ->and((new \App\Jobs\Sending\FinishCampaignSendJob(1))->queue)
        ->toBe(\App\Support\Queue\Queues::CAMPAIGNS_HIGH)
        ->and((new \App\Jobs\Sending\FlushLiveCountersJob)->queue)
        ->toBe(\App\Support\Queue\Queues::MAINTENANCE);
});
