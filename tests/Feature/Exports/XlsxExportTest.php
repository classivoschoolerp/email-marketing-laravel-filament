<?php

declare(strict_types=1);

use App\Enums\ExportFormat;
use App\Jobs\Exports\RunExportJob;
use App\Jobs\Reports\RunCampaignReportExportJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ExportJob;
use App\Models\Subscriber;
use App\Services\Exports\ExportColumns;
use App\Services\Exports\SubscriberExportWriter;
use App\Services\Reports\CampaignReportExporter;
use App\Services\Reports\CampaignReportService;
use App\Support\Tenancy\CurrentCustomer;
use OpenSpout\Reader\XLSX\Reader;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\CampaignReportFixture;

/*
 * WP-26 F-2.3.8-018 "Export any report to CSV/XLSX". Real bytes only: the
 * PK zip signature is asserted on the raw file and the values are read back
 * through openspout's XLSX reader, so a broken workbook cannot pass.
 */

/** @return array<string, list<list<string>>> sheet name => rows */
function wp26_readXlsx(string $absolutePath): array
{
    $reader = new Reader;
    $reader->open($absolutePath);

    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(static fn ($cell): string => (string) $cell->getValue(), $row->getCells());
        }

        $sheets[$sheet->getName()] = $rows;
    }

    $reader->close();

    return $sheets;
}

beforeEach(function (): void {
    Storage::fake('local');
    Mail::fake();
});

// ------------------------------------------------- subscriber exports

it('writes a real xlsx workbook of subscribers (PK signature + values read back)', function (): void {
    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer');
    app(CurrentCustomer::class)->set($customer);

    $list = EmailList::factory()->create(['customer_id' => $customer->id]);

    Subscriber::factory()->create([
        'customer_id' => $customer->id, 'list_id' => $list->id, 'email' => 'one@example.com',
    ]);
    Subscriber::factory()->create([
        'customer_id' => $customer->id, 'list_id' => $list->id, 'email' => '山田@example.com',
    ]);

    $export = ExportJob::create([
        'customer_id' => $customer->id,
        'list_id' => $list->id,
        'scope' => ExportJob::SCOPE_ALL,
        'format' => ExportFormat::Xlsx,
        'columns' => ['email', 'status'],
    ]);

    $path = app(SubscriberExportWriter::class)->write($export->refresh());

    expect($path)->toEndWith('.xlsx');

    $absolute = Storage::disk('local')->path($path);

    // A .xlsx IS a zip container — the first two bytes must be "PK".
    expect(substr(file_get_contents($absolute), 0, 2))->toBe('PK');

    $sheets = wp26_readXlsx($absolute);
    $rows = array_values($sheets)[0];

    expect($rows[0])->toBe(['EMAIL', 'STATUS'])
        ->and($rows[1][0])->toBe('one@example.com')
        // UTF-8 (CJK) survives the shared-string table.
        ->and($rows[2][0])->toBe('山田@example.com')
        ->and($export->refresh()->processed_rows)->toBe(2);
});

it('serves the xlsx download with the spreadsheet content type', function (): void {
    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer');
    app(CurrentCustomer::class)->set($customer);

    $list = EmailList::factory()->create(['customer_id' => $customer->id]);
    Subscriber::factory()->create(['customer_id' => $customer->id, 'list_id' => $list->id]);

    $export = ExportJob::create([
        'customer_id' => $customer->id,
        'list_id' => $list->id,
        'scope' => ExportJob::SCOPE_ALL,
        'format' => ExportFormat::Xlsx,
        'columns' => ExportColumns::STANDARD,
    ]);

    (new RunExportJob((int) $export->id))->handle(app(CurrentCustomer::class), app(SubscriberExportWriter::class));

    $export->refresh();

    $this->get(URL::signedRoute('lists.exports.download', ['uid' => $export->uid]))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertDownload('subscribers-'.$export->uid.'.xlsx');
});

it('still defaults to CSV when no format is chosen', function (): void {
    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer');
    app(CurrentCustomer::class)->set($customer);

    $list = EmailList::factory()->create(['customer_id' => $customer->id]);
    Subscriber::factory()->create(['customer_id' => $customer->id, 'list_id' => $list->id]);

    $export = ExportJob::create([
        'customer_id' => $customer->id,
        'list_id' => $list->id,
        'scope' => ExportJob::SCOPE_ALL,
        'columns' => ['email'],
    ]);

    expect($export->refresh()->format)->toBe(ExportFormat::Csv)
        ->and(app(SubscriberExportWriter::class)->write($export))->toEndWith('.csv');
});

// ----------------------------------------------------- report exports

it('writes a single-tab report as a one-sheet xlsx with the same rows as the csv', function (): void {
    $fixture = CampaignReportFixture::build();
    $this->actingAs($fixture->customer, 'customer');
    app(CurrentCustomer::class)->set($fixture->customer);

    $export = app(CampaignReportExporter::class)->queue($fixture->campaign, 'opens', ExportFormat::Xlsx);

    (new RunCampaignReportExportJob((int) $export->id))
        ->handle(app(CurrentCustomer::class), app(CampaignReportExporter::class));

    $export->refresh();

    expect($export->file_path)->toEndWith('.xlsx');

    $absolute = Storage::disk('local')->path((string) $export->file_path);
    expect(substr(file_get_contents($absolute), 0, 2))->toBe('PK');

    $sheets = wp26_readXlsx($absolute);

    expect($sheets)->toHaveKey('opens');

    $rows = $sheets['opens'];

    expect($rows[0])->toBe(app(CampaignReportExporter::class)->headers('opens'))
        // The fixture's s1 opened 3 times.
        ->and(collect($rows)->firstWhere(fn (array $row): bool => $row[0] === 's1@fixture.test')[1])->toBe('3');
});

it('writes the full report as ONE workbook with a sheet per tab (not a zip)', function (): void {
    $fixture = CampaignReportFixture::build();
    $this->actingAs($fixture->customer, 'customer');
    app(CurrentCustomer::class)->set($fixture->customer);

    $export = app(CampaignReportExporter::class)
        ->queue($fixture->campaign, CampaignReportExporter::FULL, 'xlsx');

    (new RunCampaignReportExportJob((int) $export->id))
        ->handle(app(CurrentCustomer::class), app(CampaignReportExporter::class));

    $export->refresh();

    expect($export->file_path)->toEndWith('.xlsx');

    $sheets = wp26_readXlsx(Storage::disk('local')->path((string) $export->file_path));

    foreach (array_merge(['summary'], CampaignReportService::TABS) as $tab) {
        expect($sheets)->toHaveKey($tab);
    }

    // top_links carries the pre-formatted share column.
    expect(collect($sheets['top_links'])->firstWhere(fn (array $row): bool => $row[0] === 'https://example.com/a'))
        ->toBe(['https://example.com/a', '2', '1', '66.7%']);
});

it('keeps the CSV/ZIP shapes untouched for the default format', function (): void {
    $fixture = CampaignReportFixture::build();
    $this->actingAs($fixture->customer, 'customer');
    app(CurrentCustomer::class)->set($fixture->customer);

    $csvTab = app(CampaignReportExporter::class)->queue($fixture->campaign, 'opens');
    $csvFull = app(CampaignReportExporter::class)->queue($fixture->campaign, CampaignReportExporter::FULL);

    foreach ([$csvTab, $csvFull] as $export) {
        (new RunCampaignReportExportJob((int) $export->id))
            ->handle(app(CurrentCustomer::class), app(CampaignReportExporter::class));
    }

    expect($csvTab->refresh()->file_path)->toEndWith('.csv')
        ->and($csvFull->refresh()->file_path)->toEndWith('.zip');
});
