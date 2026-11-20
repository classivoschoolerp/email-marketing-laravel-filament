<?php

declare(strict_types=1);

namespace App\Jobs\Surveys;

use App\Jobs\Concerns\IdempotentJob;
use App\Mail\ExportReadyMail;
use App\Models\Customer;
use App\Models\ExportJob;
use App\Services\Settings\SettingsReader;
use App\Services\Surveys\SurveyResponderExporter;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Executes one survey responder export (spec F-2.8-005) on the exports
 * queue — same lifecycle as WP-08's RunExportJob and WP-17's report export:
 * pending → processing → completed/failed, §3.25.2 retention expiry
 * (0 = keep forever, D-23) and a signed download link mailed to the customer
 * that dies with the retention window.
 */
class RunSurveyResponderExportJob implements ShouldQueue
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

    public function handle(CurrentCustomer $current, SurveyResponderExporter $exporter): void
    {
        /** @var ExportJob|null $export */
        $export = ExportJob::withoutTenancy()->find($this->exportJobId);

        if ($export === null
            || $export->scope !== SurveyResponderExporter::SCOPE
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
                $exporter->write($export);
            } catch (Throwable $exception) {
                Log::error('Survey responder export failed', [
                    'export_job_id' => $export->id,
                    'exception' => $exception,
                ]);

                $export->status = ExportJob::STATUS_FAILED;
                $export->message = __('surveys.export.failed');
                $export->finished_at = now();
                $export->save();

                return;
            }

            $retentionDays = SettingsReader::importExport()->exportRetentionDays();

            $export->expires_at = $retentionDays > 0 ? now()->addDays($retentionDays) : null;
            $export->status = ExportJob::STATUS_COMPLETED;
            $export->finished_at = now();
            $export->save();

            Mail::to($customer->email)->send(new ExportReadyMail(
                __('surveys.export.mail_name'),
                $this->signedDownloadUrl($export),
                $export->expires_at?->toDayDateTimeString(),
            ));
        });
    }

    private function signedDownloadUrl(ExportJob $export): string
    {
        if ($export->expires_at !== null) {
            return URL::temporarySignedRoute('lists.exports.download', $export->expires_at, ['uid' => $export->uid]);
        }

        return URL::signedRoute('lists.exports.download', ['uid' => $export->uid]);
    }

    public function idempotencyKey(): string
    {
        return 'survey-export-run:'.$this->exportJobId;
    }
}
