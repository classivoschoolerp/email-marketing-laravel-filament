<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\ImportSource;
use App\Jobs\Imports\RollbackImportJob;
use App\Jobs\Imports\RunImportJob;
use App\Models\ImportJob;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Re-run and rollback for the import history log (spec §2.2.7
 * F-2.2.7-013). Both are tenant-guarded HERE, not in the Filament page, so
 * the check survives any other caller (console command, API, plugin) —
 * docs/ARCHITECTURE.md rule 3.
 */
class ImportHistoryService
{
    /**
     * Sources whose rows come from a stored file that CANNOT be regenerated.
     * A re-run of these needs the original upload still on disk.
     */
    private const FILE_BACKED = [ImportSource::Csv, ImportSource::Paste];

    /**
     * Sources that fetch their own file every run. Clearing `file_path`
     * makes the re-run re-download instead of replaying a stale snapshot.
     */
    private const REFETCHED = [ImportSource::Url, ImportSource::Esp];

    /**
     * Queue a fresh run with the previous run's source, mapping and options.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException when the run belongs to another tenant
     * @throws ImportFailedException when a file-backed source no longer has its file
     */
    public function rerun(ImportJob $import): ImportJob
    {
        Gate::authorize('rerun', $import);

        if (in_array($import->source, self::FILE_BACKED, true) && ! $this->sourceFileExists($import)) {
            throw new ImportFailedException(__('imports.errors.rerun_file_missing'));
        }

        /** @var ImportJob $fresh */
        $fresh = $import->replicate([
            'uid', 'status', 'error_file_path', 'total_rows', 'processed_rows',
            'imported_count', 'updated_count', 'skipped_count', 'failed_count',
            'message', 'started_at', 'finished_at', 'rolled_back_at', 'rolled_back_count',
        ]);
        $fresh->status = ImportJob::STATUS_PENDING;

        if (in_array($import->source, self::REFETCHED, true)) {
            $fresh->file_path = null;
        }

        $fresh->save();

        RunImportJob::dispatch((int) $fresh->id);

        return $fresh;
    }

    /**
     * Queue the undo of a finished run (heavy → queued, docs/ARCHITECTURE.md rule 5).
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException when the run belongs to another tenant
     */
    public function rollback(ImportJob $import): void
    {
        Gate::authorize('rollback', $import);

        RollbackImportJob::dispatch((int) $import->id);
    }

    /** A finished, not-yet-undone run that actually changed something. */
    public function canRollback(ImportJob $import): bool
    {
        return $import->isFinished()
            && $import->rolled_back_at === null
            && ((int) $import->imported_count + (int) $import->updated_count) > 0;
    }

    private function sourceFileExists(ImportJob $import): bool
    {
        return $import->file_path !== null
            && Storage::disk('local')->exists($import->file_path);
    }
}
