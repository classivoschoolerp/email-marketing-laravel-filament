<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Jobs\Concerns\IdempotentJob;
use App\Mail\ExportReadyMail;
use App\Models\Customer;
use App\Models\ExportJob;
use App\Services\Reports\CampaignReportExporter;
use App\Services\Settings\SettingsReader;
use App\Support\Database\ReadReplica;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Executes one campaign-report export (spec §2.3.8 F-2.3.8-018, WP-17) on
 * the exports queue — same lifecycle as WP-08's RunExportJob: pending →
 * processing → completed/failed, §3.25.2 retention expiry (0 = keep forever,
 * D-23) and a signed download link mailed to the customer that dies with the
 * retention window.
 */
class RunCampaignReportExportJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    public function __construct(public readonly int $exportJobId)
    {
        $this->onQueue(Queues::EXPORTS);
    }

    public function handle(CurrentCustomer $current, CampaignReportExporter $exporter): void
    {
        /** @var ExportJob|null $export */
        $export = ExportJob::withoutTenancy()->find($this->exportJobId);

        if ($export === null
            || $export->scope !== CampaignReportExporter::SCOPE
            || $export->status !== ExportJob::STATUS_PENDING) {
            return;
        }

        $customer = Customer::query()->find($export->customer_id);

        if ($customer === null) {
            return;
        }

        $current->runAs($customer, function () use ($export, $customer, $exporter): void {
            $export->status = ExportJob::STATUS_PROCESSING;
            $export->started_at = now();
            $export->save();

            try {
                // F-4.6-007: the export body is a long sequence of aggregate
                // reads over the tracking tables — the archetypal replica
                // workload. CampaignReportExporter also stamps progress onto
                // $export while it runs; that stays correct because the
                // reporting connection routes every write to the PRIMARY
                // (config/database.php `mysql_read`, invariant 1 on
                // App\Support\Database\ReadReplica). The status transitions
                // above and below are deliberately OUTSIDE the wrapper.
                ReadReplica::run(fn () => $exporter->write($export));
            } catch (\Throwable $exception) {
                Log::error('Report export failed', ['export_job_id' => $export->id, 'exception' => $exception]);

                $export->status = ExportJob::STATUS_FAILED;
                $export->message = __('reports.export.failed');
                $export->finished_at = now();
                $export->save();

                return;
            }

            $retentionDays = SettingsReader::importExport()->exportRetentionDays();

            $export->expires_at = $retentionDays > 0 ? now()->addDays($retentionDays) : null; // 0 = keep forever (D-23)
            $export->status = ExportJob::STATUS_COMPLETED;
            $export->finished_at = now();
            $export->save();

            Mail::to($customer->email)->send(new ExportReadyMail(
                __('reports.export.mail_name', ['tab' => (string) (($export->filters ?? [])['tab'] ?? 'full')]),
                $this->signedDownloadUrl($export),
                $export->expires_at?->toDayDateTimeString(),
            ));
        });
    }

    /** Signed URL bounded by the retention expiry (F-2.2.8-005 pattern). */
    private function signedDownloadUrl(ExportJob $export): string
    {
        if ($export->expires_at !== null) {
            return URL::temporarySignedRoute('lists.exports.download', $export->expires_at, ['uid' => $export->uid]);
        }

        return URL::signedRoute('lists.exports.download', ['uid' => $export->uid]);
    }

    public function idempotencyKey(): string
    {
        return 'report-export-run:'.$this->exportJobId;
    }
}
