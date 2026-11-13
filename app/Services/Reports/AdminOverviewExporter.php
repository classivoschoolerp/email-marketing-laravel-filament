<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\LandingPage;
use App\Models\Survey;
use App\Services\Landing\LandingPageStats;
use Generator;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use RuntimeException;

/**
 * Queued CSV exports for the §3.9 / §3.10 / §3.12 module overviews (WP-40).
 *
 * Same shape as the WP-17 {@see AdminLogExporter}: a `type` registry, a file
 * on the private disk, and a signed expiring link mailed to the requesting
 * admin. Adding a type means touching the four places listed below and
 * nothing else — TYPES, headerKeys(), rows(), and the lang block.
 *
 * Per-row figures come from the module's own owner service
 * ({@see LandingPageStats}, {@see TransactionalOverviewService}) so an
 * exported number always equals the one on screen.
 */
class AdminOverviewExporter
{
    public const TYPE_SURVEYS = 'surveys';

    public const TYPE_LANDING_PAGES = 'landing_pages';

    public const TYPE_TRANSACTIONAL = 'transactional';

    /** @var list<string> */
    public const TYPES = [self::TYPE_SURVEYS, self::TYPE_LANDING_PAGES, self::TYPE_TRANSACTIONAL];

    public const DIRECTORY = 'admin/module-overview-exports';

    /**
     * Each overview belongs to an optional module; a type is exportable only
     * while that module is installed.
     */
    public static function available(string $type): bool
    {
        return match ($type) {
            self::TYPE_SURVEYS => class_exists(Survey::class),
            self::TYPE_LANDING_PAGES => class_exists(LandingPage::class) && class_exists(LandingPageStats::class),
            self::TYPE_TRANSACTIONAL => class_exists(TransactionalOverviewService::class),
            default => false,
        };
    }

    public static function relativePath(string $type, string $token): string
    {
        return self::DIRECTORY.'/'.$type.'-'.$token.'.csv';
    }

    /**
     * Writes the file and returns [path, rows written].
     *
     * @return array{0: string, 1: int}
     */
    public function write(string $type, string $token): array
    {
        if (! in_array($type, self::TYPES, true) || ! self::available($type)) {
            throw new RuntimeException('Unknown admin overview export type: '.$type);
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);

        $path = self::relativePath($type, $token);
        $writer = Writer::createFromPath($disk->path($path), 'w+');

        $writer->insertOne(array_map(
            static fn (string $key): string => __('reports.overviews.export.headers.'.$key),
            $this->headerKeys($type),
        ));

        $written = 0;

        foreach ($this->rows($type) as $row) {
            $writer->insertOne($row);
            $written++;
        }

        return [$path, $written];
    }

    /** @return list<string> */
    private function headerKeys(string $type): array
    {
        return match ($type) {
            self::TYPE_SURVEYS => ['customer', 'name', 'status', 'list', 'responders', 'completed', 'completion_rate', 'created_at'],
            self::TYPE_LANDING_PAGES => ['customer', 'name', 'slug', 'status', 'visits', 'unique_visitors', 'conversions', 'conversion_rate', 'published_at'],
            self::TYPE_TRANSACTIONAL => ['customer', 'total', 'sent', 'failed', 'bounced', 'complained', 'delivery_rate', 'bounce_rate', 'error_rate', 'avg_delivery_ms'],
            default => [],
        };
    }

    /** @return Generator<int, list<int|float|string|null>> */
    private function rows(string $type): Generator
    {
        switch ($type) {
            case self::TYPE_SURVEYS:
                $query = Survey::withoutTenancy()
                    ->with(['customer', 'list'])
                    ->withCount([
                        'responders as responders_count',
                        'completedResponders as completed_count',
                    ])
                    ->orderBy('id');

                foreach ($query->cursor() as $survey) {
                    $responders = (int) $survey->responders_count;
                    $completed = (int) $survey->completed_count;

                    yield [
                        $survey->customer?->email,
                        $survey->name,
                        $survey->status->value,
                        $survey->list?->name,
                        $responders,
                        $completed,
                        $responders > 0 ? round($completed * 100 / $responders, 1) : 0.0,
                        (string) $survey->created_at,
                    ];
                }

                return;

            case self::TYPE_LANDING_PAGES:
                $query = LandingPage::withoutTenancy()->with('customer')->orderBy('id');

                foreach ($query->cursor() as $page) {
                    $summary = app(LandingPageStats::class)->summary($page);

                    yield [
                        $page->customer?->email,
                        $page->name,
                        $page->slug,
                        $page->status->value,
                        $summary['visits'],
                        $summary['unique_visitors'],
                        $summary['conversions'],
                        $summary['conversion_rate'],
                        (string) $page->published_at,
                    ];
                }

                return;

            case self::TYPE_TRANSACTIONAL:
                foreach (app(TransactionalOverviewService::class)->perCustomer() as $row) {
                    yield [
                        $row['customer'],
                        $row['total'],
                        $row['sent'],
                        $row['failed'],
                        $row['bounced'],
                        $row['complained'],
                        $row['delivery_rate'],
                        $row['bounce_rate'],
                        $row['error_rate'],
                        $row['avg_delivery_ms'],
                    ];
                }

                return;
        }
    }
}
