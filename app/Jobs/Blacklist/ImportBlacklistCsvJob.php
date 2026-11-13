<?php

declare(strict_types=1);

namespace App\Jobs\Blacklist;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\GlobalBlacklistEmail;
use App\Services\Blacklist\BlacklistEntries;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

/**
 * Queued blacklist CSV import (§2.9.10 F-2.9.10-003 / §3.17 F-3.17-002,
 * docs/ARCHITECTURE.md rule 5). Format: one address per row — first column email,
 * optional second column reason. customer_id null = admin import
 * (install-wide rows); set = customer import (rows stamped to the tenant).
 * Invalid rows and duplicates are silently skipped (blacklist imports are
 * idempotent by nature). The uploaded file is removed afterwards.
 */
class ImportBlacklistCsvJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $operationId,
        public readonly string $filePath,
        public readonly ?int $customerId,
    ) {
        $this->onQueue(Queues::IMPORTS);
    }

    public function handle(CurrentCustomer $current): void
    {
        $current->runAs(null, function (): void {
            $disk = Storage::disk('local');

            if (! $disk->exists($this->filePath)) {
                return;
            }

            $entries = app(BlacklistEntries::class);
            $reader = Reader::createFromPath($disk->path($this->filePath));

            $batch = [];

            foreach ($reader->getRecords() as $record) {
                $record = array_values((array) $record);
                $email = mb_strtolower(trim((string) ($record[0] ?? '')));
                $reason = trim((string) ($record[1] ?? ''));

                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }

                $batch[$email] = $reason;
            }

            // Group by reason so addMany() can bulk-insert per reason value.
            foreach (array_unique($batch) as $reason) {
                $emails = array_keys(array_filter($batch, fn (string $r): bool => $r === $reason));

                $entries->addMany(
                    $emails,
                    $reason !== '' ? $reason : null,
                    GlobalBlacklistEmail::SOURCE_IMPORT,
                    $this->customerId,
                );
            }

            $disk->delete($this->filePath);
        });
    }

    public function idempotencyKey(): string
    {
        return 'blacklist-import:'.$this->operationId;
    }
}
