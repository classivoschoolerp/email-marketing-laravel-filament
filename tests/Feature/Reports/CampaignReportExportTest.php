<?php

declare(strict_types=1);

use App\Jobs\Reports\RunCampaignReportExportJob;
use App\Mail\ExportReadyMail;
use App\Models\Customer;
use App\Models\ExportJob;
use App\Services\Reports\CampaignReportExporter;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\CampaignReportFixture;

// §2.3.8 F-2.3.8-018 — queued report exports with signed downloads, reusing
// the WP-08 export-job lifecycle (retention, policy-checked download).

beforeEach(function (): void {
    $this->fixture = CampaignReportFixture::build();
    $this->actingAs($this->fixture->customer, 'customer');
    app(CurrentCustomer::class)->set($this->fixture->customer);
});

it('queues a report export job on the exports queue', function (): void {
    Queue::fake();

    $export = app(CampaignReportExporter::class)->queue($this->fixture->campaign, 'opens');

    expect($export->scope)->toBe('report')
        ->and($export->status)->toBe(ExportJob::STATUS_PENDING)
        ->and($export->filters)->toBe(['campaign_id' => $this->fixture->campaign->id, 'tab' => 'opens']);

    Queue::assertPushedOn('exports', RunCampaignReportExportJob::class);
});

it('writes an exact opens csv and mails the signed link', function (): void {
    Mail::fake();

    $export = ExportJob::query()->create([
        'customer_id' => $this->fixture->customer->id,
        'scope' => 'report',
        'columns' => [],
        'filters' => ['campaign_id' => $this->fixture->campaign->id, 'tab' => 'opens'],
    ]);

    (new RunCampaignReportExportJob($export->id))
        ->handle(app(CurrentCustomer::class), app(CampaignReportExporter::class));

    $export->refresh();

    expect($export->status)->toBe(ExportJob::STATUS_COMPLETED)
        ->and($export->processed_rows)->toBe(2)
        ->and($export->file_path)->toEndWith('.csv');

    $csv = Storage::disk('local')->get((string) $export->file_path);
    $lines = array_values(array_filter(explode("\n", trim((string) $csv))));

    expect($lines)->toHaveCount(3) // header + 2 subscribers
        ->and($lines[0])->toContain(__('reports.export.headers.times_opened'))
        ->and((string) $csv)->toContain('s1@fixture.test,3')
        ->and((string) $csv)->toContain('s2@fixture.test,1');

    Mail::assertQueued(ExportReadyMail::class); // queued mailable (transactional queue)
});

it('writes the full report as a zip with one csv per tab', function (): void {
    Mail::fake();

    $export = ExportJob::query()->create([
        'customer_id' => $this->fixture->customer->id,
        'scope' => 'report',
        'columns' => [],
        'filters' => ['campaign_id' => $this->fixture->campaign->id, 'tab' => 'full'],
    ]);

    (new RunCampaignReportExportJob($export->id))
        ->handle(app(CurrentCustomer::class), app(CampaignReportExporter::class));

    $export->refresh();

    expect($export->status)->toBe(ExportJob::STATUS_COMPLETED)
        ->and($export->file_path)->toEndWith('.zip');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path((string) $export->file_path));

    foreach (['summary', 'opens', 'clicks', 'top_links', 'bounces', 'unsubscribes', 'complaints', 'delivery'] as $tab) {
        expect($zip->locateName($tab.'.csv'))->not->toBeFalse();
    }

    $summary = (string) $zip->getFromName('summary.csv');

    // fputcsv encloses fields containing spaces — "Open rate",50.
    expect($summary)->toContain(__('reports.stats.recipients').',5')
        ->and($summary)->toContain('"'.__('reports.stats.open_rate').'",50')
        ->and($summary)->toContain(__('reports.stats.forwards').',3'); // WP-35 F-1.6-002

    $topLinks = (string) $zip->getFromName('top_links.csv');

    expect($topLinks)->toContain('https://example.com/a,2,1,66.7%');

    $zip->close();
});

it('serves the report download to the owner only', function (): void {
    Mail::fake();

    $export = ExportJob::query()->create([
        'customer_id' => $this->fixture->customer->id,
        'scope' => 'report',
        'columns' => [],
        'filters' => ['campaign_id' => $this->fixture->campaign->id, 'tab' => 'bounces'],
    ]);

    (new RunCampaignReportExportJob($export->id))
        ->handle(app(CurrentCustomer::class), app(CampaignReportExporter::class));

    $url = URL::signedRoute('lists.exports.download', ['uid' => $export->refresh()->uid]);

    $this->get($url)
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertDownload('campaign-report-'.$export->uid.'.csv');

    // Another tenant with the same signed link is refused (policy on top of signature).
    $other = Customer::factory()->create();
    $this->actingAs($other, 'customer');
    app(CurrentCustomer::class)->set($other);

    $this->get($url)->assertForbidden();
});

it('refuses to export a campaign outside the owning tenant', function (): void {
    Mail::fake();

    $other = Customer::factory()->create();

    $export = ExportJob::query()->create([
        'customer_id' => $other->id, // export owned by B, campaign owned by A
        'scope' => 'report',
        'columns' => [],
        'filters' => ['campaign_id' => $this->fixture->campaign->id, 'tab' => 'opens'],
    ]);

    (new RunCampaignReportExportJob($export->id))
        ->handle(app(CurrentCustomer::class), app(CampaignReportExporter::class));

    expect(ExportJob::withoutTenancy()->find($export->id)->status)->toBe(ExportJob::STATUS_FAILED);
});
