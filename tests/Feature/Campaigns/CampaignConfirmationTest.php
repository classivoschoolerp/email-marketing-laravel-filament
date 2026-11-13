<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Filament\Customer\Resources\CampaignResource\Pages\EditCampaign;
use App\Mail\CampaignTestMail;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\SendingDomain;
use App\Models\Subscriber;
use App\Services\Campaigns\CampaignPreflight;
use App\Services\Campaigns\CampaignRecipients;
use App\Services\Campaigns\CampaignScheduler;
use App\Services\Campaigns\CampaignTestSender;
use App\Settings\CampaignSettings;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

// §2.3.5 — campaign wizard step 4: recipients, test email, pre-flight,
// schedule/send + approval.

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
});

function wp11_ready_campaign(array $overrides = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'customer_id' => test()->customer->id,
        'email_list_id' => test()->list->id,
        'send_at' => now()->addDay(),
    ], $overrides));
}

// F-2.3.5-002 — live recipient count: confirmed subscribers, segment-aware.
it('counts confirmed subscribers of the list minus the segment filter', function (): void {
    Subscriber::factory()->count(3)->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);
    Subscriber::factory()->unsubscribed()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);
    Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'vip@corp.test',
    ]);

    $campaign = wp11_ready_campaign();

    // Unsubscribed rows are never counted.
    expect(app(CampaignRecipients::class)->count($campaign))->toBe(4);

    // Segment narrows via the SegmentQueryBuilder (email contains "corp").
    $segment = ListSegment::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);
    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'field',
        'operator' => 'contains',
        'value' => 'corp',
    ]);

    $campaign->forceFill(['list_segment_id' => $segment->id])->save();

    expect(app(CampaignRecipients::class)->count($campaign->refresh()))->toBe(1);
});

// F-2.3.5-003 — queued test email with merge tags + per-address spintax.
it('queues one test mail per address with merge tags and spintax resolved', function (): void {
    Mail::fake();

    $campaign = wp11_ready_campaign([
        'subject' => '{Great|Big} news for {SUBSCRIBER_EMAIL}',
        'content_html' => '<p>{Hello|Hi} {SUBSCRIBER_NAME} — {CAMPAIGN_NAME}</p><p>{UNSUBSCRIBE_URL}</p>',
        'name' => 'Launch',
    ]);

    app(CampaignTestSender::class)->send($campaign, ['a@test.io', 'b@test.io']);

    Mail::assertQueued(CampaignTestMail::class, 2);

    Mail::assertQueued(CampaignTestMail::class, function (CampaignTestMail $mail): bool {
        return str_contains($mail->htmlBody, 'Launch')            // {CAMPAIGN_NAME}
            && ! str_contains($mail->htmlBody, '{Hello|Hi}')      // spintax rendered
            && ! str_contains($mail->htmlBody, '{CAMPAIGN_NAME}') // tags substituted
            && str_contains($mail->subjectLine, 'news for');
    });
});

// F-2.3.5-008 — each pre-flight check reacts to its own failure mode.
it('runs the pre-flight checklist per rule', function (): void {
    $preflight = app(CampaignPreflight::class);

    $good = wp11_ready_campaign();
    expect($preflight->passes($good))->toBeTrue();

    $findCheck = function (Campaign $campaign, string $key) use ($preflight): array {
        foreach ($preflight->checks($campaign) as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }

        throw new RuntimeException('check not found: '.$key);
    };

    // Unsubscribe tag missing.
    $noUnsub = wp11_ready_campaign(['content_html' => '<p>No tag</p>']);
    expect($findCheck($noUnsub, 'unsubscribe_tag')['passed'])->toBeFalse()
        ->and($preflight->passes($noUnsub))->toBeFalse();

    // Empty subject.
    $noSubject = wp11_ready_campaign(['subject' => '']);
    expect($findCheck($noSubject, 'subject')['passed'])->toBeFalse();

    // From not set / invalid email.
    $badFrom = wp11_ready_campaign(['from_email' => 'not-an-email']);
    expect($findCheck($badFrom, 'from')['passed'])->toBeFalse();

    // Merge-tag capable from passes.
    $tagFrom = wp11_ready_campaign(['from_email' => '{COMPANY_FROM_EMAIL}']);
    expect($findCheck($tagFrom, 'from')['passed'])->toBeTrue();

    // No list.
    $noList = wp11_ready_campaign(['email_list_id' => null]);
    expect($findCheck($noList, 'list')['passed'])->toBeFalse();

    // Schedule invalid: no send_at, not immediate.
    $noSchedule = wp11_ready_campaign(['send_at' => null, 'send_immediately' => false]);
    expect($findCheck($noSchedule, 'schedule')['passed'])->toBeFalse();

    // Broken merge tag detected.
    $broken = wp11_ready_campaign([
        'content_html' => '<p>{NOT_A_REAL_TAG}</p><p>{UNSUBSCRIBE_URL}</p>',
    ]);
    $check = $findCheck($broken, 'merge_tags');
    expect($check['passed'])->toBeFalse()
        ->and($check['detail'])->toContain('{NOT_A_REAL_TAG}');

    // Subscriber field tags of the target list are legal.
    $field = ListField::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'label' => 'First name',
        'tag' => 'FNAME',
        'type' => 'text',
    ]);
    $withField = wp11_ready_campaign([
        'content_html' => '<p>Hi {FNAME}</p><p>{UNSUBSCRIBE_URL}</p>',
    ]);
    expect($findCheck($withField, 'merge_tags')['passed'])->toBeTrue();

    // Images without alt text warn but never block.
    $noAlt = wp11_ready_campaign([
        'content_html' => '<p>{UNSUBSCRIBE_URL}</p><img src="x.png"><img src="y.png" alt="Logo">',
    ]);
    $check = $findCheck($noAlt, 'image_alt');
    expect($check['passed'])->toBeFalse()
        ->and($check['level'])->toBe('warning')
        ->and($check['detail'])->toBe('1')
        ->and($preflight->passes($noAlt))->toBeTrue();

    // Blacklisted content words: warning in flag mode, error in block mode.
    $settings = app(CampaignSettings::class);
    $settings->content_blacklisted_words = ['viagra'];
    $settings->blacklisted_words_action = CampaignSettings::BLACKLISTED_WORDS_ACTION_FLAG;
    $settings->save();

    $spammy = wp11_ready_campaign([
        'content_html' => '<p>Cheap viagra {UNSUBSCRIBE_URL}</p>',
    ]);
    $check = $findCheck($spammy, 'blacklisted_words');
    expect($check['passed'])->toBeFalse()
        ->and($check['level'])->toBe('warning')
        ->and($preflight->passes($spammy))->toBeTrue(); // warnings never block

    $settings->blacklisted_words_action = CampaignSettings::BLACKLISTED_WORDS_ACTION_BLOCK;
    $settings->save();

    expect($preflight->passes($spammy))->toBeFalse();
});

/* ====================================================================== *
 *  F-2.3.5-008 — "from-domain authenticated" (WP-27B, WP-15 sending_domains)
 * ====================================================================== */

/** Find one pre-flight check by key, or null when the check did not run. */
function wp27_check(Campaign $campaign, string $key): ?array
{
    foreach (app(CampaignPreflight::class)->checks($campaign) as $check) {
        if ($check['key'] === $key) {
            return $check;
        }
    }

    return null;
}

it('passes the from-domain check when a verified sending domain covers it', function (): void {
    config()->set('emp-campaigns.preflight.require_authenticated_from_domain', true);

    SendingDomain::factory()->verified()->create([
        'customer_id' => $this->customer->id,
        'name' => 'acme.test',
    ]);

    $campaign = wp11_ready_campaign(['from_email' => 'news@acme.test']);

    $check = wp27_check($campaign, 'from_domain');

    expect($check)->not->toBeNull()
        ->and($check['passed'])->toBeTrue()
        ->and($check['level'])->toBe(CampaignPreflight::LEVEL_ERROR)
        ->and($check['detail'])->toBe('acme.test')
        ->and(app(CampaignPreflight::class)->passes($campaign))->toBeTrue();
});

it('accepts a subdomain of a verified organisational domain (relaxed alignment)', function (): void {
    config()->set('emp-campaigns.preflight.require_authenticated_from_domain', true);

    SendingDomain::factory()->verified()->create([
        'customer_id' => $this->customer->id,
        'name' => 'acme.test',
    ]);

    $campaign = wp11_ready_campaign(['from_email' => 'news@mail.acme.test']);

    expect(wp27_check($campaign, 'from_domain')['passed'])->toBeTrue();

    // ...but a look-alike domain that merely ENDS with the same letters must not.
    $lookalike = wp11_ready_campaign(['from_email' => 'news@notacme.test']);

    expect(wp27_check($lookalike, 'from_domain')['passed'])->toBeFalse();
});

it('fails the from-domain check when no verified sending domain covers it', function (): void {
    config()->set('emp-campaigns.preflight.require_authenticated_from_domain', true);

    // A pending row for the SAME domain: present, but not authenticated.
    SendingDomain::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'acme.test',
    ]);

    $campaign = wp11_ready_campaign(['from_email' => 'news@acme.test']);

    $check = wp27_check($campaign, 'from_domain');

    expect($check['passed'])->toBeFalse()
        ->and($check['level'])->toBe(CampaignPreflight::LEVEL_ERROR)
        ->and(app(CampaignPreflight::class)->passes($campaign))->toBeFalse()
        ->and(app(CampaignPreflight::class)->failures($campaign))
        ->toContain(__('campaigns.preflight.from_domain').' — acme.test');
});

it('never authenticates a from domain with another tenants verified domain', function (): void {
    config()->set('emp-campaigns.preflight.require_authenticated_from_domain', true);

    $other = Customer::factory()->create();

    app(CurrentCustomer::class)->runAs($other, fn () => SendingDomain::factory()->verified()->create([
        'customer_id' => $other->id,
        'name' => 'acme.test',
    ]));

    app(CurrentCustomer::class)->set($this->customer);

    // The tenant owns an unrelated verified domain, so the check still runs.
    SendingDomain::factory()->verified()->create([
        'customer_id' => $this->customer->id,
        'name' => 'mine.test',
    ]);

    $campaign = wp11_ready_campaign(['from_email' => 'news@acme.test']);

    expect(wp27_check($campaign, 'from_domain')['passed'])->toBeFalse();
});

it('authenticates a from domain with an operator-global sending domain', function (): void {
    config()->set('emp-campaigns.preflight.require_authenticated_from_domain', true);

    SendingDomain::factory()->system()->verified()->create(['name' => 'shared.test']);

    $campaign = wp11_ready_campaign(['from_email' => 'news@shared.test']);

    expect(wp27_check($campaign, 'from_domain')['passed'])->toBeTrue();
});

it('respects the from-domain toggle and skips the check honestly', function (): void {
    // 1. Gate off (the shipped default) → the check does not run at all.
    config()->set('emp-campaigns.preflight.require_authenticated_from_domain', false);

    SendingDomain::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'acme.test',
    ]);

    $campaign = wp11_ready_campaign(['from_email' => 'news@acme.test']);

    expect(wp27_check($campaign, 'from_domain'))->toBeNull()
        ->and(app(CampaignPreflight::class)->passes($campaign))->toBeTrue();

    // 2. Gate on but the install has NO sending domain in scope → skipped
    //    honestly rather than failing an unused feature.
    config()->set('emp-campaigns.preflight.require_authenticated_from_domain', true);
    SendingDomain::withoutGlobalScopes()->delete();

    expect(wp27_check($campaign->refresh(), 'from_domain'))->toBeNull()
        ->and(app(CampaignPreflight::class)->passes($campaign))->toBeTrue();

    // 3. Gate on, domains exist, but the From address is fully merge-tag
    //    driven → not knowable until send time, so skipped honestly.
    SendingDomain::factory()->verified()->create([
        'customer_id' => $this->customer->id,
        'name' => 'acme.test',
    ]);

    $tagDriven = wp11_ready_campaign(['from_email' => '{COMPANY_FROM_EMAIL}']);

    expect(wp27_check($tagDriven, 'from_domain'))->toBeNull();
});

// F-2.3.5-009 — schedule transitions to scheduled, or pending approval.
it('schedules a valid campaign and routes through approval when required', function (): void {
    $campaign = wp11_ready_campaign();

    $status = app(CampaignScheduler::class)->scheduleOrSend($campaign);

    expect($status)->toBe(CampaignStatus::Scheduled)
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Scheduled);

    $settings = app(CampaignSettings::class);
    $settings->approval_required = true;
    $settings->save();

    $second = wp11_ready_campaign();
    $status = app(CampaignScheduler::class)->scheduleOrSend($second);

    expect($status)->toBe(CampaignStatus::PendingApproval)
        ->and($second->refresh()->status)->toBe(CampaignStatus::PendingApproval);
});

it('refuses to schedule a campaign that fails pre-flight', function (): void {
    $campaign = wp11_ready_campaign(['content_html' => '<p>No unsubscribe tag</p>']);

    expect(fn () => app(CampaignScheduler::class)->scheduleOrSend($campaign))
        ->toThrow(ValidationException::class);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Draft);
});

// §3.25.3 max_campaign_recipients — the cap blocks scheduling.
it('refuses to schedule beyond the recipients cap', function (): void {
    Subscriber::factory()->count(3)->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);

    $settings = app(CampaignSettings::class);
    $settings->max_campaign_recipients = 2;
    $settings->save();

    $campaign = wp11_ready_campaign();

    expect(fn () => app(CampaignScheduler::class)->scheduleOrSend($campaign))
        ->toThrow(ValidationException::class);

    // D-23: 0 = unlimited.
    $settings->max_campaign_recipients = 0;
    $settings->save();

    expect(app(CampaignScheduler::class)->scheduleOrSend($campaign))->toBe(CampaignStatus::Scheduled);
});

// F-2.3.5-009/010 — the wizard confirmation action schedules or keeps draft.
it('schedules from the edit wizard confirmation step and defaults to draft', function (): void {
    $campaign = wp11_ready_campaign();

    Livewire::test(EditCampaign::class, [
        'record' => $campaign->getRouteKey(),
    ])
        ->fillForm(['confirm_action' => 'schedule'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Scheduled);

    $draft = wp11_ready_campaign();

    Livewire::test(EditCampaign::class, [
        'record' => $draft->getRouteKey(),
    ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($draft->refresh()->status)->toBe(CampaignStatus::Draft);
});

// Send-immediately stamps send_at at schedule time.
it('stamps send_at when sending immediately', function (): void {
    $campaign = wp11_ready_campaign(['send_at' => null, 'send_immediately' => true]);

    app(CampaignScheduler::class)->scheduleOrSend($campaign);

    expect($campaign->refresh()->send_at)->not->toBeNull()
        ->and($campaign->send_at->diffInSeconds(now()))->toBeLessThan(10);
});
