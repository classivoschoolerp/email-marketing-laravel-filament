<?php

declare(strict_types=1);

namespace App\Jobs\Imports;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\ImportJob;
use App\Models\ImportJobSubscriber;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Services\Activity\ActivityRecorder;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Undo one import run (spec §2.2.7 F-2.2.7-013 "…with re-run/rollback").
 * Heavy, so it runs on the imports queue like the import itself.
 *
 * SCOPE — exactly what this rolls back, and nothing more:
 *
 *   • Subscribers the run CREATED are force-deleted. They are removed for
 *     real, not soft-deleted: the row is being un-made, and a soft-deleted
 *     row would still collide with the list's unique (list_id, email).
 *   • Subscribers the run UPDATED get the custom field values it overwrote
 *     restored from the pre-update snapshot in `import_job_subscribers`.
 *     That is a complete revert, because update-on-duplicate is the ONLY
 *     thing an import does to an existing subscriber (SubscriberImporter
 *     writes field values and touches the row — it never changes email,
 *     status or list membership).
 *   • `updated_at` on a reverted subscriber stays at the import's value.
 *     Restoring it would need a second snapshot column for a timestamp
 *     nobody reads; the UI copy says "field values are restored".
 *   • Rows the run SKIPPED or FAILED changed nothing, so there is nothing
 *     to undo for them.
 *   • Custom FIELDS auto-created by the run (F-2.2.7-007) are kept. They may
 *     already hold values from other sources, and dropping a list field
 *     destroys data the import never wrote.
 *
 * Once finished the run is stamped `rolled_back_at` and its provenance rows
 * are dropped, so an import can be rolled back exactly once.
 */
class RollbackImportJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    /** Subscribers handled per query round-trip. */
    private const CHUNK = 500;

    public function __construct(public readonly int $importJobId)
    {
        $this->onQueue(Queues::IMPORTS);
    }

    public function handle(CurrentCustomer $current): void
    {
        /** @var ImportJob|null $import */
        $import = ImportJob::withoutTenancy()->find($this->importJobId);

        if ($import === null || $import->rolled_back_at !== null) {
            return;
        }

        $customer = Customer::query()->find($import->customer_id);

        if ($customer === null) {
            return;
        }

        $current->runAs($customer, function () use ($import): void {
            try {
                $this->rollback($import);
            } catch (\Throwable $exception) {
                Log::error('Import rollback failed', ['import_job_id' => $import->id, 'exception' => $exception]);

                $import->message = __('imports.errors.rollback_failed');
                $import->save();
            }
        });
    }

    private function rollback(ImportJob $import): void
    {
        $touched = $this->revertUpdates($import) + $this->deleteCreated($import);

        ImportJobSubscriber::withoutTenancy()->where('import_job_id', $import->id)->delete();

        $import->rolled_back_at = now();
        $import->rolled_back_count = $touched;
        $import->save();

        app(ActivityRecorder::class)->record(
            'import.rolled_back',
            $import,
            properties: ['subscribers' => $touched],
            customer: $import->customer()->withoutGlobalScopes()->first(),
        );
    }

    /** Force-delete every subscriber this run created. @return int rows removed */
    private function deleteCreated(ImportJob $import): int
    {
        $deleted = 0;

        do {
            $ids = ImportJobSubscriber::withoutTenancy()
                ->where('import_job_id', $import->id)
                ->where('action', ImportJobSubscriber::ACTION_CREATED)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('subscriber_id')
                ->unique()
                ->all();

            if ($ids === []) {
                return $deleted;
            }

            $deleted += Subscriber::withoutTenancy()->withTrashed()->whereIn('id', $ids)->forceDelete();

            // The ledger rows are removed as we go so the next page advances
            // even when a subscriber was already deleted by hand.
            ImportJobSubscriber::withoutTenancy()
                ->where('import_job_id', $import->id)
                ->where('action', ImportJobSubscriber::ACTION_CREATED)
                ->whereIn('subscriber_id', $ids)
                ->delete();
        } while (true);
    }

    /**
     * Put back the field values this run overwrote on existing subscribers.
     *
     * @return int subscribers reverted
     */
    private function revertUpdates(ImportJob $import): int
    {
        $reverted = 0;
        $seen = [];

        ImportJobSubscriber::withoutTenancy()
            ->where('import_job_id', $import->id)
            ->where('action', ImportJobSubscriber::ACTION_UPDATED)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($entries) use (&$reverted, &$seen): void {
                foreach ($entries as $entry) {
                    $subscriberId = (int) $entry->subscriber_id;

                    // A row updated twice by the same run: the FIRST snapshot
                    // is the pre-import state, so later ones are ignored.
                    if (isset($seen[$subscriberId])) {
                        continue;
                    }

                    $seen[$subscriberId] = true;

                    /** @var Subscriber|null $subscriber */
                    $subscriber = Subscriber::withoutTenancy()->find($subscriberId);

                    if ($subscriber === null) {
                        continue;
                    }

                    $this->restoreValues($subscriber, (array) $entry->previous_values);
                    $reverted++;
                }
            });

        return $reverted;
    }

    /**
     * Write the snapshot back to subscriber_field_values. Values are stored
     * RAW (already storage-encoded when they were read), so they go back
     * verbatim; a null snapshot means the subscriber had no value for that
     * field before the import, and the row the import created is deleted.
     *
     * @param  array<string, string|null>  $previous
     */
    private function restoreValues(Subscriber $subscriber, array $previous): void
    {
        if ($previous === []) {
            return;
        }

        $fieldIds = DB::table('list_fields')
            ->where('list_id', $subscriber->list_id)
            ->whereIn('tag', array_keys($previous))
            ->pluck('id', 'tag');

        foreach ($previous as $tag => $value) {
            $fieldId = $fieldIds->get((string) $tag);

            if ($fieldId === null) {
                continue;
            }

            if ($value === null) {
                SubscriberFieldValue::withoutTenancy()
                    ->where('subscriber_id', $subscriber->id)
                    ->where('list_field_id', $fieldId)
                    ->get()
                    ->each
                    ->delete();

                continue;
            }

            SubscriberFieldValue::withoutTenancy()->updateOrCreate(
                ['subscriber_id' => $subscriber->id, 'list_field_id' => $fieldId],
                ['value' => $value, 'customer_id' => $subscriber->customer_id],
            );
        }
    }

    public function idempotencyKey(): string
    {
        return 'import-rollback:'.$this->importJobId;
    }
}
