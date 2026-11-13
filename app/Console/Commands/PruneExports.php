<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes export files past the §3.25.2 retention window (F-2.2.8-005).
 * The export_jobs row survives as history with status "expired"; only the
 * file (and with it the signed link's target) disappears. Scheduled daily
 * in routes/console.php.
 */
class PruneExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Delete subscriber export files past the retention window';

    public function handle(): int
    {
        $expired = ExportJob::withoutTenancy()
            ->where('status', ExportJob::STATUS_COMPLETED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $export) {
            if ($export->file_path !== null) {
                Storage::disk('local')->delete($export->file_path);
            }

            $export->status = ExportJob::STATUS_EXPIRED;
            $export->file_path = null;
            $export->save();
        }

        $this->info(__('imports.export.pruned', ['count' => $expired->count()]));

        return self::SUCCESS;
    }
}
