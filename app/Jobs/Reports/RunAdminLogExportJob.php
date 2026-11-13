<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Jobs\Concerns\IdempotentJob;
use App\Mail\ExportReadyMail;
use App\Models\User;
use App\Services\Reports\AdminLogExporter;
use App\Support\Database\ReadReplica;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Queued §3.27 admin log CSV export (F-3.27-015, WP-17): writes the file on
 * the exports queue and emails the requesting admin a signed download link
 * (7-day expiry; the download route re-checks `logs.view` on top of the
 * signature).
 */
class RunAdminLogExportJob implements ShouldQueue
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

    public function handle(AdminLogExporter $exporter): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        // F-4.6-007: scanning §3.27 log tables is the heaviest read this queue
        // makes and nothing downstream depends on second-by-second freshness,
        // so it is the clearest candidate for the replica. The wrapper is a
        // no-op when no replica is configured, and the mail + signed URL below
        // stay outside it because they are not database reads.
        ReadReplica::run(fn () => $exporter->write($this->type, $this->token));

        $expiresAt = now()->addDays(7);

        Mail::to($user->email)->send(new ExportReadyMail(
            __('reports.admin.export_name', ['type' => __('reports.admin.types.'.$this->type)]),
            URL::temporarySignedRoute('reports.admin-logs.download', $expiresAt, [
                'type' => $this->type,
                'token' => $this->token,
            ]),
            $expiresAt->toDayDateTimeString(),
        ));
    }

    public function idempotencyKey(): string
    {
        return 'admin-log-export:'.$this->type.':'.$this->token;
    }
}
