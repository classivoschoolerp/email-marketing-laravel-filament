<?php

declare(strict_types=1);

use App\Mail\BlockRequestConfirmMail;
use App\Mail\CampaignFinishedMail;
use App\Mail\CronHeartbeatStaleMail;
use App\Mail\Customers\CustomerWelcomeMail;
use App\Mail\ExportReadyMail;
use App\Mail\ImportFinishedMail;
use App\Mail\QuotaReachedMail;
use App\Mail\QuotaWarningMail;
use App\Mail\SendingDomainVerificationMail;
use App\Models\Customer;
use App\Notifications\Auth\ConfirmEmailChange;
use App\Notifications\Auth\ConfirmRegistrationEmail;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\RecoveryCodesRegeneratedNotification;
use App\Notifications\Auth\ResetPasswordLink;
use App\Services\Auth\TwoFactorService;
use App\Services\SystemEmails\SystemEmailRenderer;
use Illuminate\Support\Facades\Notification;

// WP-20 — every refitted mailable/notification renders through the §3.15
// renderer: default content, and the admin override winning.

it('renders CampaignFinishedMail through the renderer with counts', function (): void {
    $mail = new CampaignFinishedMail('Spring Sale', 1200, 3, 1203);

    expect($mail->envelope()->subject)->toContain('Spring Sale')
        ->and($mail->content()->htmlString)->toContain('1,200')
        ->and($mail->content()->htmlString)->toContain('1,203');
});

it('honors an admin override in CampaignFinishedMail (F-3.15-006)', function (): void {
    app(SystemEmailRenderer::class)->saveOverride('campaign-finished', 'Done: [CAMPAIGN_NAME]', '<p>All done.</p>');

    $mail = new CampaignFinishedMail('Spring Sale', 1, 0, 1);

    expect($mail->envelope()->subject)->toBe('Done: Spring Sale')
        ->and($mail->content()->htmlString)->toBe('<p>All done.</p>');
});

it('renders ExportReadyMail with the download link and expiry line (F-3.15-011)', function (): void {
    $mail = new ExportReadyMail('Subscribers march', 'https://x.test/download?sig=1', 'Fri, Aug 8, 2026 1:00 PM');

    expect($mail->envelope()->subject)->toContain('Subscribers march')
        ->and($mail->content()->htmlString)->toContain('https://x.test/download?sig=1')
        ->and($mail->content()->htmlString)->toContain('Fri, Aug 8, 2026 1:00 PM');
});

it('renders BlockRequestConfirmMail with the signed confirm URL (F-3.15-014)', function (): void {
    $mail = new BlockRequestConfirmMail('https://x.test/block/confirm?sig=abc');

    expect($mail->content()->htmlString)->toContain('https://x.test/block/confirm?sig=abc')
        ->and($mail->envelope()->subject)->toBe(__('system-emails.defaults.block-request-confirmation.subject'));
});

it('renders CronHeartbeatStaleMail with the last-seen stamp (F-3.15-015)', function (): void {
    $mail = new CronHeartbeatStaleMail('2026-08-08T10:00:00+00:00');

    expect($mail->content()->htmlString)->toContain('2026-08-08T10:00:00+00:00');

    $never = new CronHeartbeatStaleMail(null);

    expect($never->content()->htmlString)->toContain(__('sending.heartbeat.never'));
});

it('renders the sending domain verification result mail for both outcomes (F-3.15-010)', function (): void {
    $ok = new SendingDomainVerificationMail('mail.acme.test', true, 'DKIM, SPF and DMARC records found.');

    expect($ok->envelope()->subject)->toContain('mail.acme.test')
        ->and($ok->envelope()->subject)->toContain(__('system-emails.results.verified'))
        ->and($ok->content()->htmlString)->toContain('DKIM, SPF and DMARC records found.');

    $bad = new SendingDomainVerificationMail('mail.acme.test', false);

    expect($bad->envelope()->subject)->toContain(__('system-emails.results.failed'));
});

it('renders ImportFinishedMail with the summary counts (F-3.15-011)', function (): void {
    $mail = new ImportFinishedMail('Newsletter', 1500, 20, 5, 2);

    expect($mail->envelope()->subject)->toContain('Newsletter')
        ->and($mail->content()->htmlString)->toContain('1,500')
        ->and($mail->content()->htmlString)->toContain('20')
        ->and($mail->content()->htmlString)->toContain('5')
        ->and($mail->content()->htmlString)->toContain('2');
});

it('renders the quota warning and reached mails (F-3.15-008)', function (): void {
    $warning = new QuotaWarningMail(8_000, 10_000, 80);

    expect($warning->envelope()->subject)->toContain('80')
        ->and($warning->content()->htmlString)->toContain('8,000')
        ->and($warning->content()->htmlString)->toContain('10,000');

    $reached = new QuotaReachedMail(10_000, 10_000);

    expect($reached->envelope()->subject)->toBe(__('system-emails.defaults.quota-reached.subject'))
        ->and($reached->content()->htmlString)->toContain('10,000');
});

it('renders CustomerWelcomeMail with the login link (F-3.15-002)', function (): void {
    $mail = new CustomerWelcomeMail('Ada', 'Lovelace');

    expect($mail->envelope()->subject)->toBe(
        app(SystemEmailRenderer::class)->resolve('customer-welcome', [
            'FIRST_NAME' => 'Ada', 'LAST_NAME' => 'Lovelace', 'LOGIN_URL' => '',
        ])->subject,
    )
        ->and($mail->content()->htmlString)->toContain('Ada')
        ->and($mail->content()->htmlString)->toContain(route('filament.customer.auth.login'));
});

it('renders the auth notifications through the renderer', function (): void {
    $customer = Customer::factory()->create();

    $registration = (new ConfirmRegistrationEmail('https://x.test/confirm?sig=1'))->toMail($customer);
    expect($registration->subject)->toBe(app(SystemEmailRenderer::class)->resolve('registration-confirmation', [
        'CONFIRM_URL' => 'https://x.test/confirm?sig=1',
        'EXPIRES_HOURS' => (int) config('emp.registration.confirm_ttl_hours', 48),
    ])->subject);
    expect((string) $registration->introLines[0])->toContain('https://x.test/confirm?sig=1');

    $reset = (new ResetPasswordLink('token-123'))->toMail($customer);
    expect($reset->subject)->toBe(app(SystemEmailRenderer::class)->resolve('password-reset', [
        'RESET_URL' => '', 'EXPIRES_MINUTES' => 60,
    ])->subject)
        ->and((string) $reset->introLines[0])->toContain('token-123');

    $change = (new ConfirmEmailChange('https://x.test/email-change?sig=2'))->toMail($customer);
    expect((string) $change->introLines[0])->toContain('https://x.test/email-change?sig=2');

    $changed = (new PasswordChangedNotification)->toMail($customer);
    expect($changed->subject)->toBe(app(SystemEmailRenderer::class)->resolve('password-changed')->subject);
});

it('sends the 2FA recovery-codes notice when codes are regenerated (F-3.15-005)', function (): void {
    Notification::fake();

    $customer = Customer::factory()->create();
    app(TwoFactorService::class)->generateRecoveryCodes($customer);

    Notification::assertSentTo($customer, RecoveryCodesRegeneratedNotification::class);

    // The notice never contains the codes themselves.
    $mail = (new RecoveryCodesRegeneratedNotification)->toMail($customer);
    expect($mail->subject)->toBe(app(SystemEmailRenderer::class)->resolve('twofa-recovery-codes')->subject);
});
