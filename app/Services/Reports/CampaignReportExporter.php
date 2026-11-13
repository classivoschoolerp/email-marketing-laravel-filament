<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\ExportFormat;
use App\Jobs\Reports\RunCampaignReportExportJob;
use App\Models\Campaign;
use App\Models\CampaignBounceLog;
use App\Models\CampaignComplaintLog;
use App\Models\CampaignDeliveryLog;
use App\Models\ExportJob;
use App\Services\Exports\Writers\RowWriter;
use App\Services\Exports\Writers\RowWriterFactory;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use RuntimeException;
use ZipArchive;

/**
 * Writes the §2.3.8 report exports (F-2.3.8-018) for an ExportJob row with
 * scope=report. filters = {campaign_id, tab}; tab ∈
 * CampaignReportService::TABS or "full". Files land on the private disk
 * under customers/{uid}/exports/ and are served through the signed,
 * policy-checked lists.exports.download route, same as subscriber exports
 * (F-2.2.8-005 pattern).
 *
 * WP-26 completed the row: `format` (CSV default, XLSX) is chosen at queue
 * time. Shape per format —
 *   - csv, single tab  → one .csv
 *   - csv, full        → .zip with one .csv per tab + summary.csv
 *   - xlsx, single tab → one .xlsx, one sheet
 *   - xlsx, full       → ONE .xlsx workbook with a sheet per tab (openspout
 *                        multi-sheet); no ZIP needed, which is the whole
 *                        reason a spreadsheet format is worth offering.
 */
class CampaignReportExporter
{
    public const SCOPE = 'report';

    public const FULL = 'full';

    public function __construct(private readonly CampaignReportService $reports) {}

    /**
     * Creates the queued export row + dispatches the runner (F-2.3.8-018).
     * Callers (row action, bulk action, tab export buttons) must authorize
     * `view` on the campaign first — D-28 for bulk paths.
     */
    public function queue(Campaign $campaign, string $tab = self::FULL, ExportFormat|string|null $format = null): ExportJob
    {
        /** @var ExportJob $export */
        $export = ExportJob::query()->create([
            'customer_id' => $campaign->customer_id,
            'scope' => self::SCOPE,
            'status' => ExportJob::STATUS_PENDING,
            'columns' => [],
            'format' => $format instanceof ExportFormat ? $format : ExportFormat::fromValue($format),
            'filters' => ['campaign_id' => $campaign->id, 'tab' => $tab],
        ]);

        RunCampaignReportExportJob::dispatch($export->id);

        return $export;
    }

    /** Writes the export file and returns its storage path. */
    public function write(ExportJob $export): string
    {
        $campaign = $this->campaign($export);
        $tab = (string) (($export->filters ?? [])['tab'] ?? self::FULL);
        $format = $export->format instanceof ExportFormat ? $export->format : ExportFormat::fromValue($export->format);

        $disk = Storage::disk('local');
        $disk->makeDirectory($export->storageDirectory());

        $extension = match (true) {
            $format === ExportFormat::Xlsx => 'xlsx',
            $tab === self::FULL => 'zip',
            default => 'csv',
        };

        $path = $export->storageDirectory().'/'.$export->uid.'.'.$extension;
        $absolute = $disk->path($path);

        $written = match (true) {
            $format === ExportFormat::Xlsx => $this->writeWorkbook($campaign, $tab, $absolute),
            $tab === self::FULL => $this->writeZip($campaign, $absolute),
            default => $this->writeCsv($campaign, $tab, $absolute),
        };

        $export->file_path = $path;
        $export->total_rows = $written;
        $export->processed_rows = $written;
        $export->save();

        return $path;
    }

    /**
     * XLSX: one sheet for a single tab, one sheet per tab for the full
     * report. openspout streams rows, so a large delivery-log tab costs the
     * same memory as a small one.
     */
    private function writeWorkbook(Campaign $campaign, string $tab, string $absolute): int
    {
        $writer = RowWriterFactory::make(ExportFormat::Xlsx, $absolute);
        $written = 0;

        try {
            $tabs = $tab === self::FULL
                ? array_merge(['summary'], CampaignReportService::TABS)
                : [$tab];

            foreach ($tabs as $sheetTab) {
                $writer->sheet($sheetTab);
                $written += $this->writeSheet($writer, $campaign, $sheetTab);
            }
        } finally {
            $writer->close();
        }

        return $written;
    }

    /** @return int data rows written (header row excluded) */
    private function writeSheet(RowWriter $writer, Campaign $campaign, string $tab): int
    {
        $rows = 0;

        $writer->writeRow($this->headers($tab));

        foreach ($this->rows($campaign, $tab) as $row) {
            $writer->writeRow($row);
            $rows++;
        }

        return $rows;
    }

    private function campaign(ExportJob $export): Campaign
    {
        $campaignId = (int) (($export->filters ?? [])['campaign_id'] ?? 0);

        $campaign = Campaign::withoutTenancy()
            ->where('customer_id', $export->customer_id) // tenant pin, never trust the id alone
            ->find($campaignId);

        if ($campaign === null) {
            throw new RuntimeException('Report export references a campaign outside the owning tenant.');
        }

        return $campaign;
    }

    private function writeCsv(Campaign $campaign, string $tab, string $absolute): int
    {
        $writer = Writer::createFromPath($absolute, 'w+');

        return $this->writeTab($writer, $campaign, $tab);
    }

    /** Full report: one CSV per tab + the header-stat summary. */
    private function writeZip(Campaign $campaign, string $absolute): int
    {
        $zip = new ZipArchive;

        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the report export archive.');
        }

        $written = 0;

        foreach (array_merge(['summary'], CampaignReportService::TABS) as $tab) {
            $writer = Writer::createFromString();
            $written += $this->writeTab($writer, $campaign, $tab);
            $zip->addFromString($tab.'.csv', $writer->toString());
        }

        $zip->close();

        return $written;
    }

    /** @return int data rows written (header row excluded) */
    private function writeTab(Writer $writer, Campaign $campaign, string $tab): int
    {
        $rows = 0;

        $writer->insertOne($this->headers($tab));

        foreach ($this->rows($campaign, $tab) as $row) {
            $writer->insertOne($row);
            $rows++;
        }

        return $rows;
    }

    /** @return list<string> */
    public function headers(string $tab): array
    {
        return array_map(
            fn (string $key): string => __('reports.export.headers.'.$key),
            match ($tab) {
                'summary' => ['metric', 'value'],
                'opens' => ['subscriber', 'times_opened', 'first_open', 'last_open', 'ip', 'device'],
                'clicks' => ['url', 'clicks', 'unique_clickers'],
                'top_links' => ['url', 'clicks', 'unique_clickers', 'share'],
                'bounces' => ['subscriber', 'bounce_type', 'message', 'date'],
                'unsubscribes' => ['subscriber', 'date', 'ip', 'reason'],
                'complaints' => ['subscriber', 'feedback_type', 'message', 'date'],
                'delivery' => ['subscriber', 'status', 'server', 'message_id', 'response', 'retries', 'date'],
                default => throw new RuntimeException('Unknown report tab: '.$tab),
            },
        );
    }

    /** @return \Generator<int, list<int|float|string|null>> */
    public function rows(Campaign $campaign, string $tab): \Generator
    {
        yield from match ($tab) {
            'summary' => $this->summaryRows($campaign),
            'opens' => $this->opensRows($campaign),
            'clicks' => $this->clicksRows($campaign),
            'top_links' => $this->topLinksRows($campaign),
            'bounces' => $this->bouncesRows($campaign),
            'unsubscribes' => $this->unsubscribesRows($campaign),
            'complaints' => $this->complaintsRows($campaign),
            'delivery' => $this->deliveryRows($campaign),
            default => throw new RuntimeException('Unknown report tab: '.$tab),
        };
    }

    private function summaryRows(Campaign $campaign): \Generator
    {
        foreach ($this->reports->headerStats($campaign) as $metric => $value) {
            yield [__('reports.stats.'.$metric), $value ?? '—'];
        }
    }

    private function opensRows(Campaign $campaign): \Generator
    {
        foreach ($this->reports->opensQuery($campaign)->orderBy('subscriber_uid')->cursor() as $row) {
            yield [
                $row->subscriber_email ?? $row->subscriber_uid,
                (int) $row->times_opened,
                (string) $row->first_open_at,
                (string) $row->last_open_at,
                $row->ip,
                $row->device,
            ];
        }
    }

    private function clicksRows(Campaign $campaign): \Generator
    {
        foreach ($this->reports->clicksQuery($campaign)->orderByDesc('clicks_count')->cursor() as $row) {
            yield [(string) $row->url, (int) $row->clicks_count, (int) $row->unique_clickers];
        }
    }

    private function topLinksRows(Campaign $campaign): \Generator
    {
        foreach ($this->reports->topLinks($campaign) as $row) {
            yield [$row['url'], $row['clicks'], $row['unique_clickers'], $row['percent'].'%'];
        }
    }

    private function bouncesRows(Campaign $campaign): \Generator
    {
        foreach ($this->reports->bouncesQuery($campaign)->orderBy('id')->cursor() as $row) {
            /** @var CampaignBounceLog $row */
            yield [
                $row->subscriber?->email ?? $row->email,
                $row->bounce_type->value,
                $row->message,
                (string) $row->created_at,
            ];
        }
    }

    private function unsubscribesRows(Campaign $campaign): \Generator
    {
        foreach ($this->reports->unsubscribesQuery($campaign)->orderBy('campaign_track_unsubscribes.id')->cursor() as $row) {
            yield [
                $row->subscriber_email ?? $row->subscriber_uid,
                (string) $row->created_at,
                $row->ip,
                $row->unsubscribe_reason,
            ];
        }
    }

    private function complaintsRows(Campaign $campaign): \Generator
    {
        foreach ($this->reports->complaintsQuery($campaign)->orderBy('id')->cursor() as $row) {
            /** @var CampaignComplaintLog $row */
            yield [
                $row->subscriber?->email ?? $row->email,
                $row->feedback_type,
                $row->message,
                (string) $row->created_at,
            ];
        }
    }

    private function deliveryRows(Campaign $campaign): \Generator
    {
        foreach ($this->reports->deliveryLogsQuery($campaign)->orderBy('id')->cursor() as $row) {
            /** @var CampaignDeliveryLog $row */
            yield [
                $row->subscriber?->email,
                (string) $row->status,
                $row->deliveryServer?->name,
                $row->message_id,
                $row->response,
                (int) $row->retries,
                (string) $row->created_at,
            ];
        }
    }
}
