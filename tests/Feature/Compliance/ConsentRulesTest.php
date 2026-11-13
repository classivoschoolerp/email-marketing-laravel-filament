<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Enums\OptInType;
use App\Enums\SubscriberStatus;
use App\Models\Campaign;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Campaigns\CampaignPreflight;
use App\Services\Campaigns\CampaignRecipients;
use App\Services\Privacy\ConsentRules;
use Illuminate\Support\Carbon;

/*
 * WP-42 — F-4.5-003 CASL and country-specific consent rules.
 *
 * Three things are pinned here:
 *   1. the rule REGISTRY resolves the right law per country, with a default;
 *   2. SEND-TIME: implied consent (no double opt-in confirmation) ages out of
 *      the regime's window at the CampaignRecipients choke point, so the
 *      wizard count, the send wave and the API can never disagree;
 *   3. SCHEDULE-TIME: the pre-flight refuses a single-opt-in list, or a list
 *      with no sender identification, under a regime that demands them.
 * Everything is off unless emp-consent.enforce is on — proved too.
 */

function consentList(string $country, array $overrides = []): EmailList
{
    return EmailList::factory()->create(array_merge([
        'company_country' => $country,
        'company_name' => 'Acme Inc.',
        'company_address_1' => '1 Acme Way',
        'company_city' => 'Springfield',
        'company_zip' => '62704',
        'opt_in' => OptInType::Double,
    ], $overrides));
}

function consentCampaign(EmailList $list, array $overrides = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'customer_id' => $list->customer_id,
        'email_list_id' => $list->id,
        'status' => CampaignStatus::Draft,
        'subject' => 'Hello',
        'from_name' => 'Acme',
        'from_email' => 'news@acme.test',
        'content_html' => '<p>Hi <a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></p>',
    ], $overrides));
}

/**
 * Express consent is a real double opt-in click: `confirm_ip` set by the
 * public confirm route. Implied consent is anything else — the row still
 * carries a `confirmed_at`, because Subscriber::creating stamps one on every
 * confirmed row, which is exactly why the rules do not trust that column.
 *
 * @param  array{confirm_ip?: ?string, subscribed_at?: ?Carbon}  $consent
 */
function consentSubscriber(EmailList $list, string $email, array $consent): Subscriber
{
    return Subscriber::factory()->create([
        'customer_id' => $list->customer_id,
        'list_id' => $list->id,
        'email' => $email,
        'status' => SubscriberStatus::Confirmed,
        'subscribed_at' => $consent['subscribed_at'] ?? Carbon::now(),
        'confirm_ip' => $consent['confirm_ip'] ?? null,
    ]);
}

/* ------------------------------------------------ the rule registry */

it('resolves the governing law from the list country, with a default (F-4.5-003)', function (): void {
    $rules = app(ConsentRules::class);

    expect($rules->forCountry('CA')->law)->toBe('CASL')
        ->and($rules->forCountry('ca')->key)->toBe('casl')
        ->and($rules->forCountry('CA')->requiresExpressConsent)->toBeTrue()
        ->and($rules->forCountry('CA')->impliedConsentDays)->toBe(730)
        ->and($rules->forCountry('DE')->law)->toBe('GDPR / ePrivacy')
        ->and($rules->forCountry('DE')->forbidsImpliedConsent())->toBeTrue()
        ->and($rules->forCountry('GB')->law)->toBe('UK PECR')
        ->and($rules->forCountry('AU')->law)->toBe('Spam Act 2003')
        ->and($rules->forCountry('AU')->requiresExpressConsent)->toBeFalse()
        // Unmapped and empty both fall back to CAN-SPAM.
        ->and($rules->forCountry('US')->law)->toBe('CAN-SPAM')
        ->and($rules->forCountry('ZZ')->key)->toBe('default')
        ->and($rules->forCountry(null)->key)->toBe('default')
        ->and($rules->forCountry('')->key)->toBe('default');

    // An unknown regime key never explodes — it degrades to the default.
    expect($rules->rule('made-up')->key)->toBe('default');
});

it('reads the governing law from the campaigns own list (F-4.5-003)', function (): void {
    $canadian = consentCampaign(consentList('CA'));
    $american = consentCampaign(consentList('US'));

    expect(app(ConsentRules::class)->forCampaign($canadian)->law)->toBe('CASL')
        ->and(app(ConsentRules::class)->forCampaign($american)->law)->toBe('CAN-SPAM');
});

/* ------------------------------------------------ send-time exclusion */

it('drops CASL implied consent older than the 24-month window (F-4.5-003)', function (): void {
    config(['emp-consent.enforce' => true]);

    $list = consentList('CA');
    $campaign = consentCampaign($list);

    // Express consent — a recorded double opt-in confirmation. Never expires.
    consentSubscriber($list, 'express-old@x.test', [
        'confirm_ip' => '203.0.113.7',
        'subscribed_at' => Carbon::now()->subYears(5),
    ]);

    // Implied consent still inside the 730-day window.
    consentSubscriber($list, 'implied-fresh@x.test', [
        'confirm_ip' => null,
        'subscribed_at' => Carbon::now()->subDays(729),
    ]);

    // Implied consent that has aged out.
    consentSubscriber($list, 'implied-stale@x.test', [
        'confirm_ip' => null,
        'subscribed_at' => Carbon::now()->subDays(731),
    ]);

    // Undateable: implied consent with no subscribe date. Subscriber::creating
    // stamps `subscribed_at` on every new row, so this can only be legacy or
    // externally-written data — written straight to the table here to prove
    // the defensive branch drops it rather than mailing on an unprovable date.
    $undateable = consentSubscriber($list, 'undateable@x.test', ['confirm_ip' => null]);
    DB::table('subscribers')->where('id', $undateable->id)->update(['subscribed_at' => null]);

    $recipients = app(CampaignRecipients::class)->query($campaign)
        ->pluck('subscribers.email')->sort()->values()->all();

    expect($recipients)->toBe(['express-old@x.test', 'implied-fresh@x.test'])
        ->and(app(CampaignRecipients::class)->count($campaign))->toBe(2);
});

it('drops every implied consent under a regime that forbids it (F-4.5-003)', function (): void {
    config(['emp-consent.enforce' => true]);

    $list = consentList('DE');
    $campaign = consentCampaign($list);

    consentSubscriber($list, 'confirmed@x.test', ['confirm_ip' => '203.0.113.7']);
    consentSubscriber($list, 'implied@x.test', [
        'confirm_ip' => null,
        'subscribed_at' => Carbon::now()->subDay(),
    ]);

    expect(app(CampaignRecipients::class)->query($campaign)->pluck('subscribers.email')->all())
        ->toBe(['confirmed@x.test']);
});

it('leaves CAN-SPAM audiences untouched (F-4.5-003)', function (): void {
    config(['emp-consent.enforce' => true]);

    $list = consentList('US');
    $campaign = consentCampaign($list);

    consentSubscriber($list, 'confirmed@x.test', ['confirm_ip' => '203.0.113.7']);
    consentSubscriber($list, 'implied@x.test', [
        'confirm_ip' => null,
        'subscribed_at' => Carbon::now()->subYears(10),
    ]);

    expect(app(CampaignRecipients::class)->query($campaign)->pluck('subscribers.email')->sort()->values()->all())
        ->toBe(['confirmed@x.test', 'implied@x.test']);
});

it('changes nothing at all while enforcement is off (F-4.5-003)', function (): void {
    config(['emp-consent.enforce' => false]);

    $list = consentList('CA');
    $campaign = consentCampaign($list);

    consentSubscriber($list, 'implied-stale@x.test', [
        'confirm_ip' => null,
        'subscribed_at' => Carbon::now()->subYears(10),
    ]);

    expect(app(CampaignRecipients::class)->count($campaign))->toBe(1);

    $keys = array_column(app(CampaignPreflight::class)->checks($campaign), 'key');

    expect($keys)->not->toContain('consent_opt_in')
        ->and($keys)->not->toContain('consent_sender_identification');
});

/* ------------------------------------------------ schedule-time gate */

it('blocks scheduling a single-opt-in list under an express-consent law (F-4.5-003)', function (): void {
    config(['emp-consent.enforce' => true]);

    $single = consentCampaign(consentList('CA', ['opt_in' => OptInType::Single]));
    $double = consentCampaign(consentList('CA'));

    $check = collect(app(CampaignPreflight::class)->checks($single))->firstWhere('key', 'consent_opt_in');

    expect($check)->not->toBeNull()
        ->and($check['passed'])->toBeFalse()
        ->and($check['level'])->toBe(CampaignPreflight::LEVEL_ERROR)
        ->and($check['detail'])->toBe('CASL')
        ->and(app(CampaignPreflight::class)->failures($single))->toContain($check['label'].' — CASL');

    expect(collect(app(CampaignPreflight::class)->checks($double))->firstWhere('key', 'consent_opt_in')['passed'])
        ->toBeTrue();
});

it('never asks a CAN-SPAM list for confirmed opt-in (F-4.5-003)', function (): void {
    config(['emp-consent.enforce' => true]);

    $campaign = consentCampaign(consentList('US', ['opt_in' => OptInType::Single]));

    $keys = array_column(app(CampaignPreflight::class)->checks($campaign), 'key');

    // CAN-SPAM does not require express consent — only identification.
    expect($keys)->not->toContain('consent_opt_in')
        ->and($keys)->toContain('consent_sender_identification');
});

it('requires sender identification where the law demands it (F-4.5-003)', function (): void {
    config(['emp-consent.enforce' => true]);

    $anonymous = consentCampaign(consentList('CA', [
        'company_name' => '',
        'company_address_1' => null,
        'company_city' => null,
        'company_zip' => null,
    ]));

    $check = collect(app(CampaignPreflight::class)->checks($anonymous))
        ->firstWhere('key', 'consent_sender_identification');

    expect($check['passed'])->toBeFalse()
        ->and($check['detail'])->toBe('CASL');

    $identified = consentCampaign(consentList('CA'));

    expect(collect(app(CampaignPreflight::class)->checks($identified))
        ->firstWhere('key', 'consent_sender_identification')['passed'])->toBeTrue();
});

it('keeps consent rules inside the tenant fence (F-4.5-003)', function (): void {
    config(['emp-consent.enforce' => true]);

    $mine = consentList('CA');
    $theirs = consentList('CA');

    $campaign = consentCampaign($mine);

    consentSubscriber($mine, 'mine@x.test', ['confirm_ip' => '203.0.113.7']);
    consentSubscriber($theirs, 'theirs@x.test', ['confirm_ip' => '203.0.113.8']);

    expect(app(CampaignRecipients::class)->query($campaign)->pluck('subscribers.email')->all())
        ->toBe(['mine@x.test'])
        ->and($mine->customer_id)->not->toBe($theirs->customer_id);
});
