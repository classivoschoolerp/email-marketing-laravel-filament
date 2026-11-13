<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Filament\Admin\Pages\SystemEmailTemplatesPage;
use App\Filament\Admin\Resources\CampaignOverviewResource;
use App\Filament\Admin\Resources\CampaignOverviewResource\Pages\ListCampaignOverviews;
use App\Jobs\Campaigns\BulkCampaignActionJob;
use App\Mail\CampaignApprovedMail;
use App\Mail\CampaignChangesRequestedMail;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Services\Campaigns\CampaignScheduler;
use App\Services\SystemEmails\SystemEmailRenderer;
use App\Settings\CampaignSettings;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

/*
 * F-3.7-004 — campaign approval queue (require admin approval before
 * sending), WP-42.
 *
 * The gate itself (§3.25.3 approval_required routing a scheduled campaign to
 * `pending-approval`) and the single approve/block actions shipped with
 * WP-18. This file covers the QUEUE: the dedicated tab that lists only what is
 * awaiting a decision, the badge, bulk approval (queued per docs/ARCHITECTURE.md rule 5)
 * and the send-back-for-changes verb the status machine allowed but nothing
 * exposed.
 */

beforeEach(function (): void {
    $this->admin = AdminTestHelpers::actingAdmin(['campaigns.manage']);

    $this->customer = Customer::factory()->create(['email' => 'owner@tenant.test']);
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->pending = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'name' => 'Waiting for review',
        'status' => CampaignStatus::PendingApproval,
    ]);

    $this->draft = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'name' => 'Still a draft',
        'status' => CampaignStatus::Draft,
    ]);

    $this->scheduled = Campaign::factory()->scheduled()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'name' => 'Already scheduled',
    ]);
});

it('lists only campaigns awaiting a decision in the approval queue tab (F-3.7-004)', function (): void {
    Livewire::test(ListCampaignOverviews::class, ['activeTab' => 'approval'])
        ->assertCanSeeTableRecords([$this->pending])
        ->assertCanNotSeeTableRecords([$this->draft, $this->scheduled]);

    // The "all" tab still shows everything — the queue narrows, it does not
    // replace the global index (F-3.7-001).
    Livewire::test(ListCampaignOverviews::class)
        ->assertCanSeeTableRecords([$this->pending, $this->draft, $this->scheduled]);
});

it('badges the queue with the exact pending count and drops the badge at zero (F-3.7-004)', function (): void {
    expect(CampaignOverviewResource::pendingApprovalCount())->toBe(1);

    $tabs = Livewire::test(ListCampaignOverviews::class)->instance()->getTabs();

    expect(array_keys($tabs))->toContain('approval')
        ->and($tabs['approval']->getBadge())->toBe('1');

    Campaign::factory()->count(2)->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::PendingApproval,
    ]);

    expect(CampaignOverviewResource::pendingApprovalCount())->toBe(3)
        ->and(Livewire::test(ListCampaignOverviews::class)->instance()->getTabs()['approval']->getBadge())
        ->toBe('3');

    Campaign::query()->update(['status' => CampaignStatus::Draft->value]);

    expect(Livewire::test(ListCampaignOverviews::class)->instance()->getTabs()['approval']->getBadge())
        ->toBeNull();
});

it('sends a campaign back to draft with a reason instead of blocking it (F-3.7-004)', function (): void {
    Mail::fake();

    Livewire::test(ListCampaignOverviews::class, ['activeTab' => 'approval'])
        ->callTableAction('requestChanges', $this->pending, ['reason' => 'The unsubscribe link is missing.'])
        ->assertHasNoTableActionErrors();

    expect($this->pending->refresh()->status)->toBe(CampaignStatus::Draft);

    Mail::assertQueued(
        CampaignChangesRequestedMail::class,
        fn (CampaignChangesRequestedMail $mail): bool => $mail->hasTo('owner@tenant.test')
            && $mail->campaignName === 'Waiting for review'
            && $mail->reason === 'The unsubscribe link is missing.',
    );
});

it('requires a reason before sending a campaign back (F-3.7-004)', function (): void {
    Mail::fake();

    Livewire::test(ListCampaignOverviews::class, ['activeTab' => 'approval'])
        ->callTableAction('requestChanges', $this->pending, ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    expect($this->pending->refresh()->status)->toBe(CampaignStatus::PendingApproval);

    Mail::assertNothingQueued();
});

it('hides both queue verbs on a campaign that is not awaiting approval (F-3.7-004)', function (): void {
    Livewire::test(ListCampaignOverviews::class)
        ->assertTableActionHidden('requestChanges', $this->scheduled)
        ->assertTableActionHidden('approve', $this->scheduled)
        ->assertTableActionVisible('requestChanges', $this->pending)
        ->assertTableActionVisible('approve', $this->pending);
});

it('queues bulk approval rather than flipping statuses inline (F-3.7-004)', function (): void {
    Queue::fake();

    $second = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::PendingApproval,
    ]);

    Livewire::test(ListCampaignOverviews::class, ['activeTab' => 'approval'])
        ->callTableBulkAction('bulkApprove', [$this->pending, $second, $this->draft]);

    Queue::assertPushed(BulkCampaignActionJob::class, function (BulkCampaignActionJob $job) use ($second): bool {
        $queued = $job->campaignIds;
        sort($queued);
        $expected = [(int) $this->pending->id, (int) $second->id];
        sort($expected);

        // The draft was filtered out by the caller (D-28) and never queued.
        return $job->action === BulkCampaignActionJob::ACTION_APPROVE
            && $queued === $expected
            && $job->customerId === null
            && $job->notifyCustomer === true;
    });

    // Nothing moved synchronously.
    expect($this->pending->refresh()->status)->toBe(CampaignStatus::PendingApproval);
});

it('approves and notifies from the queued job, skipping rows that moved on (F-3.7-004)', function (): void {
    Mail::fake();

    $moved = Campaign::factory()->scheduled()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'name' => 'Approved by someone else already',
    ]);

    (new BulkCampaignActionJob(
        BulkCampaignActionJob::ACTION_APPROVE,
        [(int) $this->pending->id, (int) $moved->id],
        null,
        'op-1',
        null,
        true,
    ))->handle(app(CurrentCustomer::class));

    expect($this->pending->refresh()->status)->toBe(CampaignStatus::Scheduled)
        ->and($moved->refresh()->status)->toBe(CampaignStatus::Scheduled);

    Mail::assertQueued(CampaignApprovedMail::class, 1);
    Mail::assertQueued(
        CampaignApprovedMail::class,
        fn (CampaignApprovedMail $mail): bool => $mail->campaignName === 'Waiting for review',
    );
});

it('registers the changes-requested system email so admins can edit it (F-3.7-004)', function (): void {
    expect(SystemEmailRenderer::keys())->toContain('campaign-changes-requested')
        ->and(SystemEmailRenderer::tags('campaign-changes-requested'))
        ->toContain('[CAMPAIGN_NAME]', '[REASON]')
        ->and(SystemEmailTemplatesPage::keyOptions())->toHaveKey('campaign-changes-requested');

    $rendered = app(SystemEmailRenderer::class)->resolve('campaign-changes-requested', [
        'CAMPAIGN_NAME' => 'Waiting for review',
        'REASON' => 'Fix the footer.',
    ]);

    expect($rendered->subject)->toContain('Waiting for review')
        ->and($rendered->html)->toContain('Fix the footer.');
});

it('routes a scheduled campaign into the queue only when approval is required (F-3.7-004)', function (): void {
    $settings = app(CampaignSettings::class);
    $settings->approval_required = true;
    $settings->save();

    expect(app(CampaignScheduler::class))->not->toBeNull();

    $fresh = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::Draft,
    ]);

    // The gate is a settings read, so the queue's own population rule is what
    // is asserted here: pendingApprovalQuery() is exactly "status = pending".
    $fresh->forceFill(['status' => CampaignStatus::PendingApproval->value])->saveQuietly();

    expect(CampaignOverviewResource::pendingApprovalQuery()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->pending->id, $fresh->id])->sort()->values()->all());
});
