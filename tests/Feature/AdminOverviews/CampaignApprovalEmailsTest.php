<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Filament\Admin\Pages\SystemEmailTemplatesPage;
use App\Mail\CampaignApprovalRequestMail;
use App\Mail\CampaignApprovedMail;
use App\Mail\CampaignBlockedMail;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\User;
use App\Services\Campaigns\CampaignStatusMachine;
use App\Services\SystemEmails\SystemEmailRenderer;
use Illuminate\Support\Facades\Mail;

// WP-18 — §3.15 F-3.15-007: campaign approval request / approved / blocked
// system emails, registered in the renderer key registry, editable on the
// admin templates page, and queued at the right lifecycle moments.

// The three keys live in the renderer registry with their merge tags…
it('registers the three approval keys in the renderer registry', function (): void {
    expect(SystemEmailRenderer::keys())
        ->toContain('campaign-approval-request', 'campaign-approved', 'campaign-blocked');

    expect(SystemEmailRenderer::tags('campaign-approval-request'))
        ->toContain('[CAMPAIGN_NAME]', '[CUSTOMER_NAME]', '[REVIEW_URL]')
        ->and(SystemEmailRenderer::tags('campaign-blocked'))->toContain('[REASON]');
});

// …which automatically surfaces them on the §3.15 admin editor page.
it('lists the approval templates on the system email templates page', function (): void {
    $options = SystemEmailTemplatesPage::keyOptions();

    expect($options)
        ->toHaveKey('campaign-approval-request')
        ->toHaveKey('campaign-approved')
        ->toHaveKey('campaign-blocked');
});

// Observer: entering pending-approval queues the request mail to every
// admin user — regardless of which code path made the transition.
it('queues the approval request to all admins when a campaign enters pending approval', function (): void {
    Mail::fake();

    $adminA = User::factory()->create(['email' => 'ops-a@admin.test']);
    $adminB = User::factory()->create(['email' => 'ops-b@admin.test']);

    $customer = Customer::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $list = EmailList::factory()->create(['customer_id' => $customer->id]);
    $campaign = Campaign::factory()->create([
        'customer_id' => $customer->id, 'email_list_id' => $list->id, 'name' => 'Big launch',
    ]);

    // The status machine is the single writer of status (D-57); the WP-11
    // scheduler routes through it when approval_required is on.
    app(CampaignStatusMachine::class)->transition($campaign, CampaignStatus::PendingApproval);

    Mail::assertQueued(CampaignApprovalRequestMail::class, fn (CampaignApprovalRequestMail $mail): bool => $mail->hasTo('ops-a@admin.test') && $mail->campaignName === 'Big launch');
    Mail::assertQueued(CampaignApprovalRequestMail::class, fn (CampaignApprovalRequestMail $mail): bool => $mail->hasTo('ops-b@admin.test'));

    // No duplicate mail on unrelated updates.
    $campaign->forceFill(['notes' => 'x'])->save();
    Mail::assertQueued(CampaignApprovalRequestMail::class, 2);
});

// The three mailables render through the renderer with their tags…
it('renders the approval mails through the system email renderer', function (): void {
    $request = new CampaignApprovalRequestMail('Spring Sale', 'Ada Lovelace', 'https://x.test/admin/campaigns-overview');

    expect($request->envelope()->subject)->toContain('Spring Sale')
        ->and($request->content()->htmlString)->toContain('Ada Lovelace')
        ->and($request->content()->htmlString)->toContain('https://x.test/admin/campaigns-overview');

    $approved = new CampaignApprovedMail('Spring Sale');

    expect($approved->envelope()->subject)
        ->toBe(app(SystemEmailRenderer::class)->resolve('campaign-approved', ['CAMPAIGN_NAME' => 'Spring Sale'])->subject);

    $blocked = new CampaignBlockedMail('Spring Sale', 'Not allowed content');

    expect($blocked->envelope()->subject)->toContain('Spring Sale')
        ->and($blocked->content()->htmlString)->toContain('Not allowed content');
});

// …and an admin override wins over the built-in default (F-3.15-016).
it('honors admin overrides for the approval emails', function (): void {
    app(SystemEmailRenderer::class)->saveOverride('campaign-approved', 'GO: [CAMPAIGN_NAME]', '<p>Approved!</p>');

    $mail = new CampaignApprovedMail('Spring Sale');

    expect($mail->envelope()->subject)->toBe('GO: Spring Sale')
        ->and($mail->content()->htmlString)->toBe('<p>Approved!</p>');
});
