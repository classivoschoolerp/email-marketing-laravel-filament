<?php

declare(strict_types=1);

namespace App\Jobs\Exports;

use App\Enums\NotificationEvent;
use App\Jobs\Concerns\IdempotentJob;
use App\Mail\ExportReadyMail;
use App\Models\Customer;
use App\Models\ExportJob;
use App\Services\Activity\ActivityRecorder;
use App\Services\Exports\SubscriberExportWriter;
use App\Services\Notifications\CustomerNotifier;
use App\Services\Settings\SettingsReader;
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
 * Executes one ExportJob on the exports queue (spec §2.2.8; docs/ARCHITECTURE.md rule
 * 5): streams the CSV in chunks, stamps the retention expiry from §3.25.2
 * (0 = keep forever, D-23) and emails the customer a signed download link
 * that dies with the retention window (F-2.2.8-004/005).
 */
class RunExportJob implements ShouldQueue
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

    public function handle(CurrentCustomer $current, SubscriberExportWriter $writer): void
    {
        /** @var ExportJob|null $export */
        $export = ExportJob::withoutTenancy()->find($this->exportJobId);

        if ($export === null || $export->status !== ExportJob::STATUS_PENDING) {
            return;
        }

        $customer = Customer::query()->find($export->customer_id);

        if ($customer === null) {
            return;
        }

        $current->runAs($customer, function () use ($export, $customer, $writer): void {
            $export->status = ExportJob::STATUS_PROCESSING;
            $export->started_at = now();
            $export->save();

            try {
                $writer->write($export);
            } catch (\Throwable $exception) {
                Log::error('Export run failed', ['export_job_id' => $export->id, 'exception' => $exception]);

                $export->status = ExportJob::STATUS_FAILED;
                $export->message = __('imports.export.failed');
                $export->finished_at = now();
                $export->save();

                return;
            }

            $retentionDays = SettingsReader::importExport()->exportRetentionDays();

            $export->expires_at = $retentionDays > 0 ? now()->addDays($retentionDays) : null; // 0 = keep forever (D-23)
            $export->status = ExportJob::STATUS_COMPLETED;
            $export->finished_at = now();
            $export->save();

            // WP-39 §2.15.3 / F-4.3-002 — bell entry + preference-gated email.
            $notifier = app(CustomerNotifier::class);
            $downloadUrl = $this->signedDownloadUrl($export);

            $notifier->inApp(
                $customer,
                NotificationEvent::ExportReady,
                __('notifications.in_app.export_ready.title'),
                __('notifications.in_app.export_ready.body', ['type' => $this->exportName($export)]),
                status: 'success',
                icon: 'heroicon-o-arrow-down-tray',
                actions: [[
                    'label' => __('notifications.in_app.export_ready.action'),
                    'url' => $downloadUrl,
                ]],
            );

            app(ActivityRecorder::class)->record(
                'export.completed',
                $export,
                customer: $customer,
                subjectLabel: $this->exportName($export),
            );

            if (! $notifier->allowsEmail($customer, NotificationEvent::ExportReady)) {
                return;
            }

            Mail::to($customer->email)->send(new ExportReadyMail(
                $this->exportName($export),
                $downloadUrl,
                $export->expires_at?->toDayDateTimeString(),
            ));
        });
    }

    /** Signed URL bounded by the retention expiry (F-2.2.8-005). */
    private function signedDownloadUrl(ExportJob $export): string
    {
        if ($export->expires_at !== null) {
            return URL::temporarySignedRoute('lists.exports.download', $export->expires_at, ['uid' => $export->uid]);
        }

        return URL::signedRoute('lists.exports.download', ['uid' => $export->uid]);
    }

    private function exportName(ExportJob $export): string
    {
        $listName = $export->list()->withoutGlobalScopes()->first()?->name;

        return $listName ?? __('imports.export.default_name');
    }

    public function idempotencyKey(): string
    {
        return 'export-run:'.$this->exportJobId;
    }
}
