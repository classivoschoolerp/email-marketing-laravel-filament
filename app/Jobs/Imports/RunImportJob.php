<?php

declare(strict_types=1);

namespace App\Jobs\Imports;

use App\Enums\ImportSource;
use App\Enums\NotificationEvent;
use App\Enums\SubscriberStatus;
use App\Jobs\Concerns\IdempotentJob;
use App\Mail\ImportFinishedMail;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ImportJob;
use App\Models\ImportJobSubscriber;
use App\Services\Activity\ActivityRecorder;
use App\Services\Imports\ErrorReportWriter;
use App\Services\Imports\ImportEmailValidator;
use App\Services\Imports\ImportFailedException;
use App\Services\Imports\ImportFileValidator;
use App\Services\Imports\ImportReaderRegistry;
use App\Services\Imports\Readers\ImportReader;
use App\Services\Imports\SubscriberImporter;
use App\Services\Notifications\CustomerNotifier;
use App\Services\Settings\SettingsReader;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Executes one ImportJob on the imports queue (spec §2.2.7; docs/ARCHITECTURE.md rule
 * 5). Rows are processed in batches of the §3.25.2 import batch size, with
 * counters flushed to import_jobs after every batch so the Filament poll
 * widget shows live progress + ETA (F-2.2.7-011). When the §3.25.2 max
 * execution time is exceeded mid-run the job flushes and requeues itself
 * with the current offset — chunked continuation instead of a dead worker.
 */
class RunImportJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $importJobId,
        public readonly int $offset = 0,
    ) {
        $this->onQueue(Queues::IMPORTS);
    }

    public function handle(CurrentCustomer $current): void
    {
        /** @var ImportJob|null $import */
        $import = ImportJob::withoutTenancy()->find($this->importJobId);

        if ($import === null || $import->isFinished()) {
            return;
        }

        $customer = Customer::query()->find($import->customer_id);

        if ($customer === null) {
            return;
        }

        // The admin memory guardrail (F-3.25.2-002) must not leak past this job:
        // on the sync queue (tests) or a long-lived worker, a lowered limit would
        // starve every subsequent job in the same process.
        $previousLimit = (string) ini_get('memory_limit');

        try {
            $current->runAs($customer, function () use ($import): void {
                try {
                    $this->run($import);
                } catch (ImportFailedException $exception) {
                    $this->markFailed($import, $exception->getMessage());
                } catch (\Throwable $exception) {
                    Log::error('Import run failed', ['import_job_id' => $import->id, 'exception' => $exception]);
                    $this->markFailed($import, __('imports.errors.unexpected'));
                }
            });
        } finally {
            ini_set('memory_limit', $previousLimit);
        }
    }

    /** Raise the process memory ceiling to $mb, never lower it. */
    private function raiseMemoryLimit(int $mb): void
    {
        if ($mb <= 0) {
            return;
        }

        $current = $this->memoryLimitBytes((string) ini_get('memory_limit'));

        // -1 (unlimited) is already the highest possible ceiling.
        if ($current < 0) {
            return;
        }

        $desired = $mb * 1024 * 1024;

        if ($desired > $current) {
            ini_set('memory_limit', $mb.'M');
        }
    }

    private function memoryLimitBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function run(ImportJob $import): void
    {
        $settings = SettingsReader::importExport();

        // Memory head-room from admin settings (F-2.2.7-015 / F-3.25.2-002).
        // The setting means "an import may use up to N MB", so it may only RAISE
        // the ceiling: lowering it cannot reclaim memory already allocated, and
        // PHP throws outright once usage exceeds the new value — which on a
        // long-lived queue worker would fail imports for reasons unrelated to
        // the import itself.
        $this->raiseMemoryLimit($settings->importMemoryLimitMb());

        // 0 = unlimited (D-23).
        $deadline = $settings->importMaxExecutionSeconds() > 0
            ? microtime(true) + $settings->importMaxExecutionSeconds()
            : null;

        $reader = $this->reader($import);

        if ($this->offset === 0) {
            $this->validateStoredFile($import);
            $reader->prepare($import);

            $import->total_rows = $reader->totalRows($import);
            $import->status = ImportJob::STATUS_PROCESSING;
            $import->started_at = now();
            $import->save();
        } elseif ($import->status !== ImportJob::STATUS_PROCESSING) {
            $import->status = ImportJob::STATUS_PROCESSING;
            $import->save();
        }

        $options = (array) $import->options;
        $importedStatus = SubscriberStatus::tryFrom((string) ($options['imported_status'] ?? '')) ?? SubscriberStatus::Unconfirmed;

        /** @var EmailList $list */
        $list = EmailList::withoutTenancy()->findOrFail($import->list_id);
        $strategy = (string) ($options['duplicate_strategy'] ?? $list->import_duplicate_handling ?? 'update');

        $importer = app(SubscriberImporter::class);
        $errorWriter = app(ErrorReportWriter::class);

        // F-2.2.7-014 — optional per-row syntax + MX check through the D-12
        // built-in engine, with MX answers memoized per domain for this chunk.
        $emailValidator = ($options['validate_emails'] ?? false) ? app(ImportEmailValidator::class) : null;

        $batchSize = max(1, $settings->importBatchSize());
        $position = $this->offset;
        $inBatch = 0;

        /** @var list<array<string, mixed>> $ledger */
        $ledger = [];

        foreach ($reader->rows($import, $this->offset) as $row) {
            $rejection = $emailValidator?->reject($row['email']);

            if ($rejection !== null) {
                $import->failed_count++;
                $errorWriter->append($import, $row['line'], $row['email'], [$rejection]);
            } else {
                $outcome = $importer->importRow($list, $row['email'], $row['fields'], $importedStatus, $strategy);

                match ($outcome['result']) {
                    SubscriberImporter::RESULT_IMPORTED => $import->imported_count++,
                    SubscriberImporter::RESULT_UPDATED => $import->updated_count++,
                    SubscriberImporter::RESULT_SKIPPED => $import->skipped_count++,
                    default => $import->failed_count++,
                };

                if ($outcome['result'] === SubscriberImporter::RESULT_FAILED) {
                    $errorWriter->append($import, $row['line'], $row['email'], $outcome['errors']);
                }

                $entry = $this->ledgerEntry($import, $outcome);

                if ($entry !== null) {
                    $ledger[] = $entry;
                }
            }

            $position++;
            $inBatch++;

            if ($inBatch >= $batchSize) {
                $this->writeLedger($ledger);
                $ledger = [];
                $this->flush($import, $position);
                $inBatch = 0;

                if ($deadline !== null && microtime(true) > $deadline) {
                    // Continue in a fresh job (chunked queued processing).
                    self::dispatch($this->importJobId, $position);

                    return;
                }
            }
        }

        $this->writeLedger($ledger);
        $this->flush($import, $position);

        $import->status = $import->failed_count > 0
            ? ImportJob::STATUS_COMPLETED_WITH_ERRORS
            : ImportJob::STATUS_COMPLETED;
        $import->finished_at = now();
        $import->save();

        // Import summary mail (spec §3.15 F-3.15-011, WP-20) — rendered
        // through SystemEmailRenderer, admin-overridable.
        $owner = Customer::query()->find($import->customer_id);

        if ($owner === null) {
            return;
        }

        // WP-39 §2.15.3 / F-4.3-002 — bell entry + preference-gated email.
        $notifier = app(CustomerNotifier::class);

        $notifier->inApp(
            $owner,
            NotificationEvent::ImportComplete,
            __('notifications.in_app.import_complete.title'),
            __('notifications.in_app.import_complete.body', [
                'imported' => (int) $import->imported_count,
                'updated' => (int) $import->updated_count,
                'failed' => (int) $import->failed_count,
            ]),
            status: $import->failed_count > 0 ? 'warning' : 'success',
            icon: 'heroicon-o-arrow-down-on-square',
        );

        app(ActivityRecorder::class)->record(
            'import.completed',
            $import,
            properties: [
                'imported' => (int) $import->imported_count,
                'updated' => (int) $import->updated_count,
                'failed' => (int) $import->failed_count,
            ],
            customer: $owner,
            subjectLabel: (string) $list->name,
        );

        if (trim((string) $owner->email) !== ''
            && $notifier->allowsEmail($owner, NotificationEvent::ImportComplete)) {
            Mail::to($owner->email)->queue(new ImportFinishedMail(
                (string) $list->name,
                (int) $import->imported_count,
                (int) $import->updated_count,
                (int) $import->skipped_count,
                (int) $import->failed_count,
            ));
        }
    }

    /**
     * Server-side re-check of the stored upload against the §3.25.2 limits
     * (F-2.2.7-015) — the UI validates too, but the queue never trusts it.
     */
    private function validateStoredFile(ImportJob $import): void
    {
        if ($import->file_path === null || ! in_array($import->source, [ImportSource::Csv, ImportSource::Paste], true)) {
            return;
        }

        if (! Storage::disk('local')->exists($import->file_path)) {
            throw new ImportFailedException(__('imports.errors.file_missing'));
        }

        $originalName = (string) (((array) $import->options)['original_filename'] ?? basename($import->file_path));

        $errors = app(ImportFileValidator::class)->validate(
            $originalName,
            (int) Storage::disk('local')->size($import->file_path),
        );

        if ($errors !== []) {
            throw new ImportFailedException(implode(' ', $errors));
        }
    }

    private function flush(ImportJob $import, int $processed): void
    {
        $import->processed_rows = $processed;
        $import->save();
    }

    /**
     * Provenance row for F-2.2.7-013 rollback, or null when the outcome
     * changed no subscriber (skipped/failed rows have nothing to undo).
     *
     * @param  array{result: string, errors: list<string>, subscriber_id: int|null, previous: array<string, string|null>|null}  $outcome
     * @return array<string, mixed>|null
     */
    private function ledgerEntry(ImportJob $import, array $outcome): ?array
    {
        if ($outcome['subscriber_id'] === null) {
            return null;
        }

        return [
            'customer_id' => (int) $import->customer_id,
            'import_job_id' => (int) $import->id,
            'subscriber_id' => (int) $outcome['subscriber_id'],
            'action' => $outcome['result'] === SubscriberImporter::RESULT_UPDATED
                ? ImportJobSubscriber::ACTION_UPDATED
                : ImportJobSubscriber::ACTION_CREATED,
            'previous_values' => $outcome['previous'] === null
                ? null
                : json_encode($outcome['previous'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ];
    }

    /**
     * Bulk-insert one batch of provenance rows. Deliberately a single
     * query-builder insert: the ledger is written for EVERY imported row, so
     * per-row model saves would double the write cost of an import.
     *
     * @param  list<array<string, mixed>>  $ledger
     */
    private function writeLedger(array $ledger): void
    {
        if ($ledger === []) {
            return;
        }

        DB::table('import_job_subscribers')->insert($ledger);
    }

    private function markFailed(ImportJob $import, string $message): void
    {
        $import->status = ImportJob::STATUS_FAILED;
        $import->message = $message;
        $import->finished_at = now();
        $import->save();
    }

    /**
     * WP-36 (§6.1 F-6.1-014): resolution moved into ImportReaderRegistry so a
     * plugin can add or replace an import source without a core change. The
     * registry keeps the exact built-in mapping as its fallback.
     */
    private function reader(ImportJob $import): ImportReader
    {
        return app(ImportReaderRegistry::class)->for($import->source);
    }

    public function idempotencyKey(): string
    {
        return 'import-run:'.$this->importJobId.':'.$this->offset;
    }
}
