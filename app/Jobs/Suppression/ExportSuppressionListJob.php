<?php

declare(strict_types=1);

namespace App\Jobs\Suppression;

use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\SuppressionList;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;

/**
 * Queued suppression-list export (§2.9.11 F-2.9.11-005): writes the list's
 * addresses to customers/{uid}/exports/suppression-{list_uid}.csv on the
 * private disk; downloaded through the policy-checked route in
 * routes/modules/blacklist.php.
 */
class ExportSuppressionListJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $operationId,
        public readonly int $suppressionListId,
    ) {
        $this->onQueue(Queues::EXPORTS);
    }

    public function handle(): void
    {
        $list = SuppressionList::withoutTenancy()->find($this->suppressionListId);

        if ($list === null) {
            return;
        }

        $disk = Storage::disk('local');
        $path = self::pathFor($list);
        $disk->makeDirectory(dirname($path));

        $writer = Writer::createFromPath($disk->path($path), 'w+');
        $writer->insertOne([__('blacklist.csv.email'), __('blacklist.csv.created_at')]);

        $list->emails()->orderBy('id')->chunkById(500, function ($rows) use ($writer): void {
            foreach ($rows as $row) {
                $writer->insertOne([$row->email, (string) $row->created_at]);
            }
        });
    }

    public static function pathFor(SuppressionList $list): string
    {
        $customer = Customer::query()->findOrFail($list->customer_id);

        return 'customers/'.$customer->uid.'/exports/suppression-'.$list->uid.'.csv';
    }

    public function idempotencyKey(): string
    {
        return 'suppression-export:'.$this->operationId;
    }
}
