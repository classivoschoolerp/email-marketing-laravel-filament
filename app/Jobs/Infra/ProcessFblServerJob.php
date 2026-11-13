<?php

declare(strict_types=1);

namespace App\Jobs\Infra;

use App\Models\FblServer;
use App\Services\Fbl\FblProcessor;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued single-server FBL run (spec §2.9.5, WP-15) — backs the admin
 * "process now" action. The scheduled path is the fbl:process command.
 */
class ProcessFblServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $fblServerId)
    {
        $this->onQueue(Queues::BOUNCES);
    }

    public function handle(FblProcessor $processor): void
    {
        $server = FblServer::withoutTenancy()->find($this->fblServerId);

        if ($server !== null) {
            $processor->process($server);
        }
    }
}
