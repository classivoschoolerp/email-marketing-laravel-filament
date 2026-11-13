<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\CampaignResource\Pages\CampaignReport;
use App\Filament\Customer\Resources\CampaignResource\Widgets\BouncesTable;
use App\Filament\Customer\Resources\CampaignResource\Widgets\ClicksTable;
use App\Filament\Customer\Resources\CampaignResource\Widgets\ClicksOverTimeChart;
use App\Filament\Customer\Resources\CampaignResource\Widgets\DeliveryLogsTable;
use App\Filament\Customer\Resources\CampaignResource\Widgets\OpensOverTimeChart;
use App\Filament\Customer\Resources\CampaignResource\Widgets\OpensTable;
use App\Filament\Customer\Resources\CampaignResource\Widgets\UnsubscribesTable;
use App\Models\Customer;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\CampaignReportFixture;

// §2.3.8 — the campaign report page: header stats, tabs, charts, tenant
// isolation and the sending auto-refresh (F-2.3.8-001..016/021).

beforeEach(function (): void {
    $this->fixture = CampaignReportFixture::build();
    $this->actingAs($this->fixture->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->fixture->customer);
});

it('renders the report page with the exact header stats', function (): void {
    $this->get(CampaignResource::getUrl('report', ['record' => $this->fixture->campaign]))
        ->assertOk()
        ->assertSee(__('reports.stats.recipients'))
        ->assertSee(__('reports.stats.open_rate'))
        ->assertSee('50%')   // open rate + CTOR
        ->assertSee('25%')   // click rate
        ->assertSee(__('reports.stats.forwards')) // P2 placeholder rendered as —
        ->assertSee(__('reports.stats.bounce_split', ['hard' => 1, 'soft' => 1, 'internal' => 1]));
});

// Cross-tenant denial: customer B can never open A's report.
it('denies the report page to another tenant', function (): void {
    $other = Customer::factory()->create();
    $this->actingAs($other, 'customer');
    app(CurrentCustomer::class)->set($other);

    $this->get(CampaignResource::getUrl('report', ['record' => $this->fixture->campaign]))
        ->assertNotFound();
});

// F-2.3.8-021 — auto-refresh only while sending.
it('polls the page while the campaign is sending', function (): void {
    $url = CampaignResource::getUrl('report', ['record' => $this->fixture->campaign]);

    $this->get($url)->assertOk()->assertDontSee('wire:poll.10s', false);

    $this->fixture->campaign->forceFill(['status' => 'sending'])->save();

    $this->get($url)
        ->assertOk()
        ->assertSee('wire:poll.10s', false)
        ->assertSee(__('reports.page.sending_progress', ['percent' => 100]));
});

// F-2.3.8-004 — opens tab lists per-subscriber aggregates.
it('shows the opens tab rows', function (): void {
    Livewire::test(OpensTable::class, ['record' => $this->fixture->campaign])
        ->assertSee('s1@fixture.test')
        ->assertSee('s2@fixture.test')
        ->assertSee('desktop');
});

// F-2.3.8-005 — clicks tab shows per-URL counts.
it('shows the clicks tab rows', function (): void {
    Livewire::test(ClicksTable::class, ['record' => $this->fixture->campaign])
        ->assertSee('https://example.com/a')
        ->assertSee('https://example.com/b');
});

// F-2.3.8-007/008 — bounce + unsubscribe tabs.
it('shows the bounces and unsubscribes tabs', function (): void {
    Livewire::test(BouncesTable::class, ['record' => $this->fixture->campaign])
        ->assertSee('s5@fixture.test')
        ->assertSee('550 5.1.1 user unknown');

    Livewire::test(UnsubscribesTable::class, ['record' => $this->fixture->campaign])
        ->assertSee('s2@fixture.test')
        ->assertSee('9.9.9.9')
        ->assertSee('Too many emails');
});

// F-2.3.8-010 — delivery logs tab: searchable + status filter.
it('filters the delivery logs tab by status', function (): void {
    Livewire::test(DeliveryLogsTable::class, ['record' => $this->fixture->campaign])
        ->assertSee('s1@fixture.test')
        ->assertSee('msg-id-0001')
        ->filterTable('status', 'failed')
        ->assertSee('SMTP 550 mailbox unavailable')
        ->assertDontSee('msg-id-0001');
});

// F-2.3.8-011/012 — chart widgets read the service series.
it('feeds the over-time charts from the report service', function (): void {
    $chartData = function (string $class): array {
        $widget = new $class;
        $widget->record = $this->fixture->campaign;

        return (fn (): array => $this->getCachedData())->call($widget);
    };

    $opensData = $chartData(OpensOverTimeChart::class);

    expect(array_sum($opensData['datasets'][0]['data']))->toBe(4)
        ->and($opensData['labels'])->toContain(now()->format('Y-m-d'));

    $clicksData = $chartData(ClicksOverTimeChart::class);

    expect(array_sum($clicksData['datasets'][0]['data']))->toBe(3);
});

// F-2.3.8-013..016 — geo + breakdown tabs render honestly on the page.
it('renders the geo and device tabs', function (): void {
    Livewire::test(CampaignReport::class, ['record' => $this->fixture->campaign->getRouteKey()])
        ->set('activeTab', 'geo')
        ->assertSee(__('reports.breakdowns.unknown'))
        ->set('activeTab', 'devices')
        ->assertSee('desktop')
        ->assertSee('Windows')
        ->assertSee('Gmail')
        ->assertSee('75%');
});
