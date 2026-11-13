<?php

declare(strict_types=1);

namespace App\Jobs\Suppression;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\SuppressionList;
use App\Services\Suppression\SuppressionListEmails;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

/**
 * Queued suppression-list CSV import (§2.9.11 F-2.9.11-002, docs/ARCHITECTURE.md rule
 * 5): first column of every row is the address; invalid rows and
 * duplicates are skipped. The uploaded file is removed afterwards and the
 * emails_count counter refreshed.
 */
class ImportSuppressionCsvJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $operationId,
        public readonly int $suppressionListId,
        public readonly string $filePath,
    ) {
        $this->onQueue(Queues::IMPORTS);
    }

    public function handle(CurrentCustomer $current): void
    {
        $list = SuppressionList::withoutTenancy()->find($this->suppressionListId);

        if ($list === null) {
            return;
        }

        $current->runAs(null, function () use ($list): void {
            $disk = Storage::disk('local');

            if (! $disk->exists($this->filePath)) {
                return;
            }

            $emails = [];

            foreach (Reader::createFromPath($disk->path($this->filePath))->getRecords() as $record) {
                $record = array_values((array) $record);
                $emails[] = (string) ($record[0] ?? '');
            }

            app(SuppressionListEmails::class)->addMany($list, $emails);

            $disk->delete($this->filePath);
        });
    }

    public function idempotencyKey(): string
    {
        return 'suppression-import:'.$this->operationId;
    }
}
