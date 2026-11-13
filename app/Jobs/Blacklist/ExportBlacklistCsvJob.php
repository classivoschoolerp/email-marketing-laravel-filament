<?php

declare(strict_types=1);

namespace App\Jobs\Blacklist;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\GlobalBlacklistEmail;
use App\Services\Settings\SettingsReader;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;

/**
 * Queued blacklist CSV export (§2.9.10 F-2.9.10-003 / §3.17 F-3.17-002).
 * customer_id null = admin export of every row (admin/exports/blacklist.csv
 * incl. the owning customer email); set = customer export of the rows that
 * customer can see (own rows in isolation mode, everything in shared mode)
 * to customers/{uid}/exports/blacklist.csv. Downloads go through the
 * policy-checked routes in routes/modules/blacklist.php.
 */
class ExportBlacklistCsvJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public const ADMIN_PATH = 'admin/exports/blacklist.csv';

    public function __construct(
        public readonly string $operationId,
        public readonly ?int $customerId,
    ) {
        $this->onQueue(Queues::EXPORTS);
    }

    public function handle(): void
    {
        $disk = Storage::disk('local');
        $path = $this->path();

        $directory = dirname($path);
        $disk->makeDirectory($directory);

        $writer = Writer::createFromPath($disk->path($path), 'w+');

        $header = [
            __('blacklist.csv.email'),
            __('blacklist.csv.type'),
            __('blacklist.csv.reason'),
            __('blacklist.csv.source'),
            __('blacklist.csv.created_at'),
        ];

        if ($this->customerId === null) {
            $header[] = __('blacklist.csv.customer');
        }

        $writer->insertOne($header);

        $this->query()->orderBy('id')->chunkById(500, function ($rows) use ($writer): void {
            foreach ($rows as $row) {
                $line = [
                    $row->email,
                    $row->is_domain ? 'domain' : 'email',
                    (string) $row->reason,
                    $row->source,
                    (string) $row->created_at,
                ];

                if ($this->customerId === null) {
                    $line[] = (string) $row->customer?->email;
                }

                $writer->insertOne($line);
            }
        });
    }

    public function path(): string
    {
        if ($this->customerId === null) {
            return self::ADMIN_PATH;
        }

        $customer = Customer::query()->findOrFail($this->customerId);

        return 'customers/'.$customer->uid.'/exports/blacklist.csv';
    }

    /** @return Builder<GlobalBlacklistEmail> */
    private function query(): Builder
    {
        $query = GlobalBlacklistEmail::query()->with('customer');

        if ($this->customerId === null) {
            return $query;
        }

        if (SettingsReader::blacklist()->sharedAcrossCustomers()) {
            return $query;
        }

        return $query->where('customer_id', $this->customerId);
    }

    public function idempotencyKey(): string
    {
        return 'blacklist-export:'.$this->operationId;
    }
}
