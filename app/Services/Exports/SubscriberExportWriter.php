<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Enums\ExportFormat;
use App\Models\ExportJob;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Exports\Writers\RowWriterFactory;
use App\Services\Segments\SegmentQueryBuilder;
use App\Services\Settings\SettingsReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Streams an export's subscribers into
 * customers/{uid}/exports/{uuid}.{csv|xlsx} on the private disk (spec
 * §2.2.8). The writer (league/csv or openspout, WP-26) writes directly to
 * the file handle and rows are read in chunks of the §3.25.2 export batch
 * size, so memory stays flat regardless of list size; processed_rows is
 * flushed per chunk for the history page. Row count capped by
 * export_max_rows_per_file.
 */
class SubscriberExportWriter
{
    public function __construct(
        private readonly ExportColumns $columns,
        private readonly SegmentQueryBuilder $segments,
    ) {}

    /**
     * Writes the export file and returns the storage path.
     *
     * WP-26 (F-2.3.8-018): the format is whatever the customer chose on the
     * export action — CSV (default, league/csv) or XLSX (openspout). Both
     * stream: rows are handed to the writer one at a time inside the
     * chunkById loop, so memory stays flat at any list size (§4.6
     * F-4.6-005).
     */
    public function write(ExportJob $export): string
    {
        $settings = SettingsReader::importExport();
        $batchSize = max(1, $settings->exportBatchSize());
        $cap = $settings->exportMaxRowsPerFile(); // 0 = unlimited (D-23)

        $query = $this->query($export)->with('fieldValues')->orderBy('subscribers.id');

        $total = $query->clone()->count();

        if ($cap > 0) {
            $total = min($total, $cap);
        }

        $export->total_rows = $total;
        $export->save();

        $format = $export->format instanceof ExportFormat ? $export->format : ExportFormat::fromValue($export->format);

        $disk = Storage::disk('local');
        $path = $export->storageDirectory().'/'.$export->uid.'.'.$format->extension();
        $disk->makeDirectory($export->storageDirectory());

        $writer = RowWriterFactory::make($format, $disk->path($path));
        $writer->sheet(__('imports.export.sheet_name'));

        /** @var list<string> $columnKeys */
        $columnKeys = array_values((array) $export->columns);
        $writer->writeRow($this->columns->headers($columnKeys));

        // Custom fields for every list this export can touch, keyed per list.
        $fieldsByList = ListField::withoutTenancy()
            ->where('customer_id', $export->customer_id)
            ->get()
            ->groupBy('list_id')
            ->map(fn ($fields) => $fields->keyBy('tag'));

        $written = 0;

        $query->chunkById($batchSize, function ($subscribers) use ($writer, $columnKeys, $fieldsByList, $export, $cap, &$written): bool {
            foreach ($subscribers as $subscriber) {
                if ($cap > 0 && $written >= $cap) {
                    return false;
                }

                $fields = $fieldsByList->get($subscriber->list_id) ?? collect();
                $writer->writeRow($this->columns->row($subscriber, $columnKeys, $fields));
                $written++;
            }

            $export->processed_rows = $written;
            $export->save();

            return true;
        }, 'subscribers.id', 'id');

        $writer->close();

        $export->processed_rows = $written;
        $export->file_path = $path;
        $export->save();

        return $path;
    }

    /**
     * The subscriber query for the export scope (F-2.2.8-001): all list
     * members / current filters / dynamic segment / explicit selection.
     *
     * @return Builder<Subscriber>
     */
    public function query(ExportJob $export): Builder
    {
        $query = match ($export->scope) {
            ExportJob::SCOPE_SEGMENT => $this->segmentQuery($export),
            ExportJob::SCOPE_SELECTED => Subscriber::withoutTenancy()
                ->whereKey(array_map(intval(...), (array) $export->subscriber_ids)),
            ExportJob::SCOPE_FILTERED => $this->applyFilters(
                Subscriber::withoutTenancy()->where('list_id', $export->list_id),
                (array) $export->filters,
            ),
            default => Subscriber::withoutTenancy()->where('list_id', $export->list_id),
        };

        // The tenant fence is non-negotiable regardless of scope payload.
        return $query->where('subscribers.customer_id', $export->customer_id);
    }

    /** @return Builder<Subscriber> */
    private function segmentQuery(ExportJob $export): Builder
    {
        /** @var ListSegment $segment */
        $segment = ListSegment::withoutTenancy()
            ->where('customer_id', $export->customer_id)
            ->findOrFail($export->list_segment_id);

        // Runs inside CurrentCustomer::runAs, so the tenant scope is active
        // and correct; the explicit customer_id fence is added by query().
        return $this->segments->subscribers($segment);
    }

    /**
     * @param  Builder<Subscriber>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Subscriber>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $statuses = array_filter((array) ($filters['statuses'] ?? []));

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        if (! empty($filters['source'])) {
            $query->where('source', (string) $filters['source']);
        }

        if (! empty($filters['search'])) {
            $query->where('email', 'like', '%'.$filters['search'].'%');
        }

        if (! empty($filters['subscribed_from'])) {
            $query->whereDate('subscribed_at', '>=', (string) $filters['subscribed_from']);
        }

        if (! empty($filters['subscribed_until'])) {
            $query->whereDate('subscribed_at', '<=', (string) $filters['subscribed_until']);
        }

        return $query;
    }
}
