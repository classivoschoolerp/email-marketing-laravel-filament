<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\CampaignResource\Pages\ListCampaigns;
use App\Jobs\Reports\RunCampaignReportExportJob;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ExportJob;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// F-2.3.1-005/006 (WP-17 slice) — Overview/Report row action, queued
// report export action and D-28-filtered bulk export.

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $this->campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);
});

// F-2.3.1-005 — Overview/Report links to the §2.3.8 report page.
it('exposes the overview report row action linking to the report page', function (): void {
    Livewire::test(ListCampaigns::class)
        ->assertTableActionExists('report')
        ->assertTableActionHasUrl(
            'report',
            CampaignResource::getUrl('report', ['record' => $this->campaign]),
            record: $this->campaign,
        );
});

// F-2.3.1-005 — Export report queues the full-report job.
it('queues a full report export from the row action', function (): void {
    Queue::fake();

    Livewire::test(ListCampaigns::class)
        ->callTableAction('export_report', $this->campaign);

    Queue::assertPushedOn('exports', RunCampaignReportExportJob::class);

    $export = ExportJob::query()->latest('id')->first();

    expect($export->scope)->toBe('report')
        ->and($export->customer_id)->toBe($this->customer->id)
        ->and($export->filters['tab'])->toBe('full')
        ->and($export->filters['campaign_id'])->toBe($this->campaign->id);
});

// F-2.3.1-006 — bulk export queues one job per selected campaign (D-28).
it('bulk exports reports for the selected campaigns only', function (): void {
    Queue::fake();

    $second = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    // Foreign campaign can never be reached by the bulk action (D-28 +
    // tenant scope): it is not even selectable in the table.
    $other = Customer::factory()->create();
    $foreign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): Campaign => Campaign::factory()->create(['customer_id' => $other->id]),
    );

    Livewire::test(ListCampaigns::class)
        ->callTableBulkAction('export_report', [$this->campaign, $second, $foreign]);

    expect(ExportJob::withoutTenancy()->where('scope', 'report')->count())->toBe(2)
        ->and(ExportJob::withoutTenancy()->where('customer_id', $other->id)->count())->toBe(0);

    Queue::assertPushed(RunCampaignReportExportJob::class, 2);
});
