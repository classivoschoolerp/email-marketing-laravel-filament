<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\CampaignResource\Pages\ListCampaigns;
use App\Jobs\Campaigns\BulkCampaignActionJob;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Customer;
use App\Models\EmailList;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// §2.3.1 — campaigns index: tabs, columns, search/filters, actions.

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
});

function wp12_campaign(array $overrides = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'customer_id' => test()->customer->id,
        'email_list_id' => test()->list->id,
    ], $overrides));
}

// F-2.3.1-002 — index renders with the core columns.
it('renders the index and never shows another tenants campaigns', function (): void {
    $mine = wp12_campaign(['name' => 'My campaign']);

    $other = Customer::factory()->create();
    $foreign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): Campaign => Campaign::factory()->create(['customer_id' => $other->id, 'name' => 'Foreign campaign']),
    );

    $this->get(CampaignResource::getUrl('index'))
        ->assertOk()
        ->assertSee('My campaign')
        ->assertDontSee('Foreign campaign');

    Livewire::test(ListCampaigns::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});

// F-2.3.1-001 — tabs filter by type and status.
it('filters by the type and status tabs', function (): void {
    $regular = wp12_campaign();
    $autoresponder = wp12_campaign([
        'type' => 'autoresponder',
        'ar_trigger' => 'after-subscribe',
    ]);
    $sent = wp12_campaign(['status' => CampaignStatus::Sent]);
    $paused = wp12_campaign(['status' => CampaignStatus::Paused]);

    Livewire::test(ListCampaigns::class)
        ->assertCanSeeTableRecords([$regular, $autoresponder, $sent, $paused])
        ->set('activeTab', 'autoresponder')
        ->assertCanSeeTableRecords([$autoresponder])
        ->assertCanNotSeeTableRecords([$regular, $sent])
        ->set('activeTab', 'drafts')
        ->assertCanSeeTableRecords([$regular, $autoresponder])
        ->assertCanNotSeeTableRecords([$sent, $paused])
        ->set('activeTab', 'sent')
        ->assertCanSeeTableRecords([$sent])
        ->assertCanNotSeeTableRecords([$regular, $paused])
        ->set('activeTab', 'paused')
        ->assertCanSeeTableRecords([$paused]);
});

// F-2.3.1-004 — search by name/subject + list/group/status/type/date filters.
it('searches and filters the index', function (): void {
    $groupA = CampaignGroup::factory()->create(['customer_id' => $this->customer->id]);
    $otherList = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $alpha = wp12_campaign(['name' => 'Alpha launch', 'subject' => 'Rocket day', 'campaign_group_id' => $groupA->id]);
    $beta = wp12_campaign(['name' => 'Beta digest', 'subject' => 'Weekly roundup', 'email_list_id' => $otherList->id]);

    Livewire::test(ListCampaigns::class)
        ->searchTable('Alpha')
        ->assertCanSeeTableRecords([$alpha])
        ->assertCanNotSeeTableRecords([$beta])
        ->searchTable('roundup')
        ->assertCanSeeTableRecords([$beta])
        ->assertCanNotSeeTableRecords([$alpha])
        ->searchTable('')
        ->filterTable('email_list_id', $otherList->id)
        ->assertCanSeeTableRecords([$beta])
        ->assertCanNotSeeTableRecords([$alpha])
        ->resetTableFilters()
        // Campaign groups filter (F-2.3.1-007).
        ->filterTable('campaign_group_id', $groupA->id)
        ->assertCanSeeTableRecords([$alpha])
        ->assertCanNotSeeTableRecords([$beta])
        ->resetTableFilters()
        ->filterTable('status', [CampaignStatus::Draft->value])
        ->assertCanSeeTableRecords([$alpha, $beta]);
});

it('filters by send date range', function (): void {
    $near = wp12_campaign(['send_at' => now()->addDay()]);
    $far = wp12_campaign(['send_at' => now()->addDays(30)]);

    Livewire::test(ListCampaigns::class)
        ->filterTable('send_between', [
            'from' => now()->format('Y-m-d'),
            'until' => now()->addDays(3)->format('Y-m-d'),
        ])
        ->assertCanSeeTableRecords([$near])
        ->assertCanNotSeeTableRecords([$far]);
});

// F-2.3.1-005 — per-status action visibility.
it('shows row actions according to the status machine', function (): void {
    $draft = wp12_campaign();
    $sending = wp12_campaign(['status' => CampaignStatus::Sending]);
    $paused = wp12_campaign(['status' => CampaignStatus::Paused]);

    Livewire::test(ListCampaigns::class)
        ->assertTableActionVisible('edit', $draft)
        ->assertTableActionHidden('edit', $sending)   // edit until sending
        ->assertTableActionVisible('pause', $sending)
        ->assertTableActionHidden('pause', $draft)
        ->assertTableActionVisible('resume', $paused)
        ->assertTableActionHidden('resume', $draft)
        ->assertTableActionVisible('block', $sending)
        ->assertTableActionHidden('block', $draft);
});

// F-2.3.1-002 — stats columns stay honest until WP-13 delivery data exists.
it('renders placeholder stats until delivery data exists', function (): void {
    wp12_campaign();

    $this->get(CampaignResource::getUrl('index'))
        ->assertOk()
        ->assertSee(__('campaigns.stats_pending'));
});

// F-2.3.1-006 — D-28 bulk pause/resume only touch eligible rows.
it('bulk pauses only campaigns the status machine allows', function (): void {
    $sending = wp12_campaign(['status' => CampaignStatus::Sending]);
    $scheduled = wp12_campaign(['status' => CampaignStatus::Scheduled]);
    $sent = wp12_campaign(['status' => CampaignStatus::Sent]);

    Livewire::test(ListCampaigns::class)
        ->callTableBulkAction('pause', [$sending, $scheduled, $sent]);

    expect($sending->refresh()->status)->toBe(CampaignStatus::Paused)
        ->and($scheduled->refresh()->status)->toBe(CampaignStatus::Paused)
        ->and($sent->refresh()->status)->toBe(CampaignStatus::Sent);
});

it('bulk resumes paused campaigns and bulk deletes softly', function (): void {
    $pausedMidSend = wp12_campaign(['status' => CampaignStatus::Paused, 'send_started_at' => now()]);
    $pausedBefore = wp12_campaign(['status' => CampaignStatus::Paused]);
    $draft = wp12_campaign();

    Livewire::test(ListCampaigns::class)
        ->callTableBulkAction('resume', [$pausedMidSend, $pausedBefore, $draft]);

    expect($pausedMidSend->refresh()->status)->toBe(CampaignStatus::Sending)
        ->and($pausedBefore->refresh()->status)->toBe(CampaignStatus::Scheduled)
        ->and($draft->refresh()->status)->toBe(CampaignStatus::Draft);

    Livewire::test(ListCampaigns::class)
        ->callTableBulkAction('delete', [$draft]);

    $this->assertSoftDeleted('campaigns', ['id' => $draft->id]);
});

/* ====================================================================== *
 *  F-Phase-3-Differentiat-006 — bulk actions are QUEUED, never synchronous
 * ====================================================================== */

it('queues campaign bulk actions instead of running them in the request', function (): void {
    Queue::fake();

    $sending = wp12_campaign(['status' => CampaignStatus::Sending]);
    $sent = wp12_campaign(['status' => CampaignStatus::Sent]);
    $paused = wp12_campaign(['status' => CampaignStatus::Paused]);
    $draft = wp12_campaign();

    Livewire::test(ListCampaigns::class)
        ->callTableBulkAction('pause', [$sending, $sent]);

    // Nothing happened inline — the flip belongs to the worker.
    expect($sending->refresh()->status)->toBe(CampaignStatus::Sending);

    Queue::assertPushed(
        BulkCampaignActionJob::class,
        fn (BulkCampaignActionJob $job): bool => $job->action === BulkCampaignActionJob::ACTION_PAUSE
            // D-28: the terminal "sent" row never even reaches the queue.
            && $job->campaignIds === [$sending->id]
            && $job->customerId === $this->customer->id
            && $job->queue === 'campaigns-high',
    );

    Livewire::test(ListCampaigns::class)
        ->callTableBulkAction('resume', [$paused, $draft]);

    Queue::assertPushed(
        BulkCampaignActionJob::class,
        fn (BulkCampaignActionJob $job): bool => $job->action === BulkCampaignActionJob::ACTION_RESUME
            && $job->campaignIds === [$paused->id],
    );

    Livewire::test(ListCampaigns::class)
        ->callTableBulkAction('delete', [$draft]);

    Queue::assertPushed(
        BulkCampaignActionJob::class,
        fn (BulkCampaignActionJob $job): bool => $job->action === BulkCampaignActionJob::ACTION_DELETE
            && $job->campaignIds === [$draft->id],
    );

    // Still present: the delete is the worker's job, not the request's.
    expect(Campaign::withoutTenancy()->withTrashed()->find($draft->id)->trashed())->toBeFalse();

    Queue::assertPushed(BulkCampaignActionJob::class, 3);
});

it('applies queued campaign bulk effects through the status machine only', function (): void {
    $sending = wp12_campaign(['status' => CampaignStatus::Sending]);
    $sent = wp12_campaign(['status' => CampaignStatus::Sent]);

    // The status machine is re-checked at execution time: "sent" is terminal
    // and must survive even when the caller hands its id over.
    (new BulkCampaignActionJob(
        BulkCampaignActionJob::ACTION_PAUSE,
        [$sending->id, $sent->id],
        (int) $this->customer->id,
        'op-pause-1',
    ))->handle(app(CurrentCustomer::class));

    expect($sending->refresh()->status)->toBe(CampaignStatus::Paused)
        ->and($sent->refresh()->status)->toBe(CampaignStatus::Sent);
});

it('never lets a queued campaign bulk touch another tenants rows', function (): void {
    $other = Customer::factory()->create();

    $foreign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): Campaign => Campaign::factory()->sending()->create(['customer_id' => $other->id]),
    );

    app(CurrentCustomer::class)->set($this->customer);

    (new BulkCampaignActionJob(
        BulkCampaignActionJob::ACTION_PAUSE,
        [$foreign->id],
        (int) $this->customer->id,
        'op-cross-tenant',
    ))->handle(app(CurrentCustomer::class));

    expect(Campaign::withoutTenancy()->find($foreign->id)->status)->toBe(CampaignStatus::Sending);
});

// F-2.3.4-020 — share-report action appears only when a link exists.
it('shows the share report action only when sharing is enabled', function (): void {
    $shared = wp12_campaign(['share_reports' => true, 'share_reports_secret' => 'abc']);
    $private = wp12_campaign();

    Livewire::test(ListCampaigns::class)
        ->assertTableActionVisible('share_report', $shared)
        ->assertTableActionHidden('share_report', $private);
});
