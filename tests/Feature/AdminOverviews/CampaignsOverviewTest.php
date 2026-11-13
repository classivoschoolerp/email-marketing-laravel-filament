<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Filament\Admin\Resources\CampaignOverviewResource;
use App\Filament\Admin\Resources\CampaignOverviewResource\Pages\ListCampaignOverviews;
use App\Jobs\Campaigns\BulkCampaignActionJob;
use App\Mail\CampaignApprovedMail;
use App\Mail\CampaignBlockedMail;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Settings\CampaignSettings;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;
use Tests\Support\CampaignReportFixture;

// WP-18 — §3.7 campaigns overview: global index + tabs, admin report,
// approve/block/pause/resume/delete via the status machine, D-28 bulks,
// filters and the flagged-content review tab.

beforeEach(function (): void {
    $this->admin = AdminTestHelpers::actingAdmin(['campaigns.manage']);

    $this->customerA = Customer::factory()->create(['email' => 'alice@tenant.test']);
    $this->customerB = Customer::factory()->create(['email' => 'bob@tenant.test']);

    $this->listA = EmailList::factory()->create(['customer_id' => $this->customerA->id]);
    $this->listB = EmailList::factory()->create(['customer_id' => $this->customerB->id]);

    $this->regular = Campaign::factory()->scheduled()->create([
        'customer_id' => $this->customerA->id, 'email_list_id' => $this->listA->id, 'name' => 'Alice regular',
    ]);
    $this->autoresponder = Campaign::factory()->autoresponder()->create([
        'customer_id' => $this->customerB->id, 'email_list_id' => $this->listB->id, 'name' => 'Bob autoresponder',
    ]);
});

// F-3.7-001 — global index with customer column + type tabs.
it('lists campaigns across all customers with type tabs', function (): void {
    Livewire::test(ListCampaignOverviews::class)
        ->assertCanSeeTableRecords([$this->regular, $this->autoresponder])
        ->assertSee('alice@tenant.test')
        ->assertSee('bob@tenant.test');

    Livewire::test(ListCampaignOverviews::class, ['activeTab' => 'autoresponder'])
        ->assertCanSeeTableRecords([$this->autoresponder])
        ->assertCanNotSeeTableRecords([$this->regular]);
});

// F-3.7-002 — the admin report page renders the exact WP-17 numbers.
it('renders any campaign report read-only for admins', function (): void {
    $fixture = CampaignReportFixture::build();

    $this->get(CampaignOverviewResource::getUrl('report', ['record' => $fixture->campaign]))
        ->assertOk()
        ->assertSee($fixture->customer->email)
        ->assertSee(__('dashboards.campaigns_overview.report.stats_heading'))
        ->assertSee('50%') // open rate + CTOR (fixture-exact)
        ->assertSee('25%') // click rate
        ->assertSee('https://example.com/a');
});

// F-3.7-003 — approve: pending-approval → scheduled + approved mail.
it('approves a pending campaign and mails the customer', function (): void {
    Mail::fake();

    $pending = Campaign::factory()->create([
        'customer_id' => $this->customerA->id, 'email_list_id' => $this->listA->id,
        'status' => CampaignStatus::PendingApproval, 'name' => 'Needs review',
    ]);

    Livewire::test(ListCampaignOverviews::class)
        ->callTableAction('approve', $pending);

    expect($pending->refresh()->status)->toBe(CampaignStatus::Scheduled);

    Mail::assertQueued(CampaignApprovedMail::class, fn (CampaignApprovedMail $mail): bool => $mail->campaignName === 'Needs review'
        && $mail->hasTo('alice@tenant.test'));
});

// F-3.7-003 — block from an active state + blocked mail with the reason.
it('blocks a campaign with a reason and mails the customer', function (): void {
    Mail::fake();

    Livewire::test(ListCampaignOverviews::class)
        ->callTableAction('block', $this->regular, data: ['reason' => 'Spammy subject line']);

    expect($this->regular->refresh()->status)->toBe(CampaignStatus::Blocked);

    Mail::assertQueued(CampaignBlockedMail::class, function (CampaignBlockedMail $mail): bool {
        return $mail->reason === 'Spammy subject line'
            && $mail->hasTo('alice@tenant.test')
            && str_contains($mail->content()->htmlString, 'Spammy subject line');
    });
});

// F-3.7-003 — pause / resume through the status machine (D-57 resume rule).
it('pauses and resumes any campaign', function (): void {
    $sending = Campaign::factory()->sending()->create([
        'customer_id' => $this->customerB->id, 'email_list_id' => $this->listB->id,
    ]);

    Livewire::test(ListCampaignOverviews::class)
        ->callTableAction('pause', $sending);

    expect($sending->refresh()->status)->toBe(CampaignStatus::Paused);

    Livewire::test(ListCampaignOverviews::class)
        ->callTableAction('resume', $sending);

    // send_started_at is set → resume goes back to sending (D-57).
    expect($sending->refresh()->status)->toBe(CampaignStatus::Sending);
});

// F-3.7-003 — delete any campaign.
it('deletes any campaign', function (): void {
    Livewire::test(ListCampaignOverviews::class)
        ->callTableAction('delete', $this->autoresponder);

    expect(Campaign::withoutTenancy()->withTrashed()->find($this->autoresponder->id)->trashed())->toBeTrue();
});

// F-3.7-006 — bulk pause/block skip rows the machine forbids (D-28).
it('bulk pauses and bulk blocks only legal rows', function (): void {
    Mail::fake();

    $sending = Campaign::factory()->sending()->create([
        'customer_id' => $this->customerA->id, 'email_list_id' => $this->listA->id,
    ]);
    $sent = Campaign::factory()->sent()->create([
        'customer_id' => $this->customerA->id, 'email_list_id' => $this->listA->id,
    ]);

    Livewire::test(ListCampaignOverviews::class)
        ->callTableBulkAction('bulkPause', [$sending, $sent]);

    expect($sending->refresh()->status)->toBe(CampaignStatus::Paused)
        ->and($sent->refresh()->status)->toBe(CampaignStatus::Sent); // terminal, untouched

    Livewire::test(ListCampaignOverviews::class)
        ->callTableBulkAction('bulkBlock', [$sending, $sent]);

    expect($sending->refresh()->status)->toBe(CampaignStatus::Blocked)
        ->and($sent->refresh()->status)->toBe(CampaignStatus::Sent);

    Mail::assertQueued(CampaignBlockedMail::class, 1);
});

// F-Phase-3-Differentiat-006 — the admin bulks are queued, never synchronous.
it('queues the admin campaign bulks with the D-28 filtered selection', function (): void {
    Queue::fake();

    $sending = Campaign::factory()->sending()->create([
        'customer_id' => $this->customerA->id, 'email_list_id' => $this->listA->id,
    ]);
    $sent = Campaign::factory()->sent()->create([
        'customer_id' => $this->customerA->id, 'email_list_id' => $this->listA->id,
    ]);

    Livewire::test(ListCampaignOverviews::class)
        ->callTableBulkAction('bulkPause', [$sending, $sent]);

    expect($sending->refresh()->status)->toBe(CampaignStatus::Sending); // worker's job

    Queue::assertPushed(
        BulkCampaignActionJob::class,
        fn (BulkCampaignActionJob $job): bool => $job->action === BulkCampaignActionJob::ACTION_PAUSE
            && $job->campaignIds === [$sending->id]   // terminal "sent" filtered out
            && $job->customerId === null              // admin bulk spans tenants
            && $job->notifyCustomer === false,
    );

    Livewire::test(ListCampaignOverviews::class)
        ->callTableBulkAction('bulkBlock', [$sending, $sent], data: ['reason' => 'Bulk policy breach']);

    Queue::assertPushed(
        BulkCampaignActionJob::class,
        fn (BulkCampaignActionJob $job): bool => $job->action === BulkCampaignActionJob::ACTION_BLOCK
            && $job->campaignIds === [$sending->id]
            && $job->blockReason === 'Bulk policy breach'
            && $job->notifyCustomer === true,
    );
});

it('applies the queued admin block and mails the owning customer', function (): void {
    Mail::fake();

    $sending = Campaign::factory()->sending()->create([
        'customer_id' => $this->customerA->id, 'email_list_id' => $this->listA->id, 'name' => 'Bulk blocked',
    ]);
    $sent = Campaign::factory()->sent()->create([
        'customer_id' => $this->customerA->id, 'email_list_id' => $this->listA->id,
    ]);

    (new BulkCampaignActionJob(
        BulkCampaignActionJob::ACTION_BLOCK,
        [$sending->id, $sent->id],
        null,
        'op-admin-block',
        'Bulk policy breach',
        true,
    ))->handle(app(CurrentCustomer::class));

    expect($sending->refresh()->status)->toBe(CampaignStatus::Blocked)
        ->and($sent->refresh()->status)->toBe(CampaignStatus::Sent);

    Mail::assertQueued(CampaignBlockedMail::class, 1);
    Mail::assertQueued(CampaignBlockedMail::class, fn (CampaignBlockedMail $mail): bool => $mail->campaignName === 'Bulk blocked'
        && $mail->reason === 'Bulk policy breach'
        && $mail->hasTo('alice@tenant.test'));
});

// F-3.7-005 — filters: status, customer, list, delivery server, date.
it('filters by status, customer, list and delivery server', function (): void {
    $this->regular->forceFill(['delivery_server_ids' => [77]])->save();

    Livewire::test(ListCampaignOverviews::class)
        ->filterTable('status', [CampaignStatus::Scheduled->value])
        ->assertCanSeeTableRecords([$this->regular])
        ->assertCanNotSeeTableRecords([$this->autoresponder])
        ->resetTableFilters()
        ->filterTable('customer_id', $this->customerB->id)
        ->assertCanSeeTableRecords([$this->autoresponder])
        ->assertCanNotSeeTableRecords([$this->regular])
        ->resetTableFilters()
        ->filterTable('email_list_id', $this->listA->id)
        ->assertCanSeeTableRecords([$this->regular])
        ->assertCanNotSeeTableRecords([$this->autoresponder])
        ->resetTableFilters()
        ->filterTable('delivery_server', 77)
        ->assertCanSeeTableRecords([$this->regular])
        ->assertCanNotSeeTableRecords([$this->autoresponder]);
});

// F-3.7-007 — flagged-content review tab (blacklisted words, flag mode).
it('surfaces campaigns whose stored content hits the blacklisted words', function (): void {
    $settings = app(CampaignSettings::class);
    $settings->subject_blacklisted_words = ['free money'];
    $settings->blacklisted_words_action = CampaignSettings::BLACKLISTED_WORDS_ACTION_FLAG;
    $settings->save();

    $flagged = Campaign::factory()->scheduled()->create([
        'customer_id' => $this->customerA->id, 'email_list_id' => $this->listA->id,
        'subject' => 'Get FREE MONEY now', 'name' => 'Suspicious blast',
    ]);

    expect(CampaignOverviewResource::flaggedCampaignIds())->toBe([$flagged->id]);

    Livewire::test(ListCampaignOverviews::class, ['activeTab' => 'flagged'])
        ->assertCanSeeTableRecords([$flagged])
        ->assertCanNotSeeTableRecords([$this->regular, $this->autoresponder]);
});

// Permission gate: campaigns.manage required.
it('denies the campaigns overview without campaigns.manage', function (): void {
    AdminTestHelpers::actingAdmin();

    $this->get(CampaignOverviewResource::getUrl('index'))->assertForbidden();
});
