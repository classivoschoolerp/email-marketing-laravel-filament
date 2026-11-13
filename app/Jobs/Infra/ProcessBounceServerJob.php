<?php

declare(strict_types=1);

namespace App\Jobs\Infra;

use App\Models\BounceServer;
use App\Services\Bounces\BounceProcessor;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued single-server bounce run (spec §2.9.4, WP-15) — backs the admin
 * "process now" action so mailbox polling never happens inside a UI request
 * (docs/ARCHITECTURE.md rule 5). The scheduled path is the bounces:process command.
 */
class ProcessBounceServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $bounceServerId)
    {
        $this->onQueue(Queues::BOUNCES);
    }

    public function handle(BounceProcessor $processor): void
    {
        $server = BounceServer::withoutTenancy()->find($this->bounceServerId);

        if ($server !== null) {
            $processor->process($server);
        }
    }
}
