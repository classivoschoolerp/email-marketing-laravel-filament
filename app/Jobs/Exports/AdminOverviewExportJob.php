<?php

declare(strict_types=1);

namespace App\Jobs\Exports;

use App\Jobs\Concerns\IdempotentJob;
use App\Mail\ExportReadyMail;
use App\Models\User;
use App\Services\Reports\AdminOverviewExporter;
use App\Support\Database\ReadReplica;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Queued CSV export of a §3.9 / §3.10 / §3.12 module overview (WP-40).
 *
 * Mirrors the WP-18 AdminListsExportJob exactly: file on the private disk,
 * signed 7-day link mailed to the requesting admin, permission re-checked by
 * the download controller on top of the signature. There is no ExportJob row
 * because these exports span every tenant and `export_jobs` is tenant-owned
 * (its storage directory is keyed by a customer uid).
 */
class AdminOverviewExportJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public readonly string $type,
        public readonly int $userId,
        public readonly string $token,
    ) {
        $this->onQueue(Queues::EXPORTS);
    }

    public function handle(AdminOverviewExporter $exporter): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        // F-4.6-007: a cross-tenant overview aggregate, which is exactly the
        // shape of query a replica exists to absorb. No-op without one.
        ReadReplica::run(fn () => $exporter->write($this->type, $this->token));

        $expiresAt = now()->addDays(7);

        Mail::to($user->email)->send(new ExportReadyMail(
            __('reports.overviews.export.name', [
                'type' => __('reports.overviews.types.'.$this->type),
            ]),
            URL::temporarySignedRoute('overviews.module-export.download', $expiresAt, [
                'type' => $this->type,
                'token' => $this->token,
            ]),
            $expiresAt->toDayDateTimeString(),
        ));
    }

    public function idempotencyKey(): string
    {
        return 'admin-overview-export:'.$this->type.':'.$this->token;
    }
}
