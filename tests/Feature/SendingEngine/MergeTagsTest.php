<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Services\Content\MergeTags;
use App\Settings\CampaignSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
 * §4.1 merge-tag engine (WP-13): subscriber/list/company/campaign/URL/date
 * tags, modifiers + default fallbacks, RANDOM_CONTENT, FETCH_URL and the
 * broken-tag scanner — including CJK values end-to-end.
 */

beforeEach(function (): void {
    $this->list = EmailList::factory()->create([
        'name' => 'Newsletter 通信',
        'description' => 'Weekly picks',
        'from_name' => 'Acme Sender',
        'from_email' => 'news@acme.test',
        'company_name' => 'Acme 株式会社',
        'company_address_1' => '1 Acme Way',
        'company_address_2' => 'Floor 2',
        'company_city' => 'Springfield',
        'company_zone' => 'IL',
        'company_country' => 'US',
        'company_zip' => '62704',
        'company_phone' => '+1 555 0100',
        'company_website' => 'https://acme.test',
    ]);

    $this->fname = ListField::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'tag' => 'FNAME',
        'label' => 'First name',
    ]);

    $this->lname = ListField::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'tag' => 'LNAME',
        'label' => 'Last name',
        'default_value' => 'Doe',
    ]);

    $this->subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'email' => 'taro@example.jp',
    ]);

    SubscriberFieldValue::withoutTenancy()->create([
        'customer_id' => $this->list->customer_id,
        'subscriber_id' => $this->subscriber->id,
        'list_field_id' => $this->fname->id,
        'value' => '太郎',
    ]);

    $this->campaign = Campaign::factory()->create([
        'customer_id' => $this->list->customer_id,
        'email_list_id' => $this->list->id,
        'name' => 'Summer Sale',
        'subject' => 'Big news 🎉',
        'send_at' => Carbon::parse('2026-08-10 09:30:00'),
    ]);

    $this->engine = app(MergeTags::class);
});

it('resolves subscriber tags including custom fields with CJK values', function (): void {
    $values = $this->engine->values($this->campaign, $this->list, $this->subscriber);

    expect($values['EMAIL'])->toBe('taro@example.jp')
        ->and($values['SUBSCRIBER_EMAIL'])->toBe('taro@example.jp')
        ->and($values['SUBSCRIBER_UID'])->toBe($this->subscriber->uid)
        ->and($values['FNAME'])->toBe('太郎')
        ->and($values['LNAME'])->toBe('Doe') // field default_value fallback
        ->and($values['SUBSCRIBER_NAME'])->toBe('太郎 Doe')
        ->and($values['SUBSCRIBE_DATE'])->toBe(now()->format('Y-m-d'));

    $rendered = $this->engine->render('Hi {FNAME} <{EMAIL}>', $values);

    expect($rendered)->toBe('Hi 太郎 <taro@example.jp>');
});

it('resolves list, company and campaign tags', function (): void {
    $values = $this->engine->values($this->campaign, $this->list, $this->subscriber);

    expect($values['LIST_NAME'])->toBe('Newsletter 通信')
        ->and($values['LIST_UID'])->toBe($this->list->uid)
        ->and($values['LIST_DESCRIPTION'])->toBe('Weekly picks')
        ->and($values['LIST_FROM_NAME'])->toBe('Acme Sender')
        ->and($values['LIST_FROM_EMAIL'])->toBe('news@acme.test')
        ->and($values['COMPANY_NAME'])->toBe('Acme 株式会社')
        ->and($values['COMPANY_ADDRESS_1'])->toBe('1 Acme Way')
        ->and($values['COMPANY_ADDRESS_2'])->toBe('Floor 2')
        ->and($values['COMPANY_CITY'])->toBe('Springfield')
        ->and($values['COMPANY_ZONE'])->toBe('IL')
        ->and($values['COMPANY_COUNTRY'])->toBe('United States')
        ->and($values['COMPANY_ZIP'])->toBe('62704')
        ->and($values['COMPANY_PHONE'])->toBe('+1 555 0100')
        ->and($values['COMPANY_WEBSITE'])->toBe('https://acme.test')
        ->and($values['COMPANY_FULL_ADDRESS'])->toContain('1 Acme Way')
        ->and($values['CAMPAIGN_NAME'])->toBe('Summer Sale')
        ->and($values['CAMPAIGN_SUBJECT'])->toBe('Big news 🎉')
        ->and($values['CAMPAIGN_UID'])->toBe($this->campaign->uid)
        ->and($values['CAMPAIGN_SEND_AT'])->toBe('2026-08-10 09:30');
});

it('resolves URL tags to the real WP-09 routes', function (): void {
    $values = $this->engine->values($this->campaign, $this->list, $this->subscriber);

    $tracked = route('campaigns.track.unsubscribe', [
        'campaign_uid' => $this->campaign->uid,
        'subscriber_uid' => $this->subscriber->uid,
    ]);

    expect($values['UNSUBSCRIBE_URL'])->toBe($tracked)
        ->and($values['DIRECT_UNSUBSCRIBE_URL'])->toBe($tracked)
        ->and($values['UPDATE_PROFILE_URL'])->toBe(route('lists.public.update_profile', [
            'list_uid' => $this->list->uid,
            'subscriber_uid' => $this->subscriber->uid,
        ]))
        ->and($values['WEB_VERSION_URL'])->toBe(route('campaigns.public.web_view', [
            'campaign_uid' => $this->campaign->uid,
        ]))
        ->and($values['LIST_SUBSCRIBE_URL'])->toBe(route('lists.public.subscribe', [
            'list_uid' => $this->list->uid,
        ]))
        // WP-35 (F-1.6-002 / F-1.7-004): the forward page is live now.
        ->and($values['FORWARD_URL'])->toBe(route('campaigns.track.forward', [
            'campaign_uid' => $this->campaign->uid,
            'subscriber_uid' => $this->subscriber->uid,
        ]))
        ->and($values['FORWARD_FRIEND_URL'])->toBe($values['FORWARD_URL']);
});

it('neutralises personal URLs without a subscriber context', function (): void {
    $values = $this->engine->values($this->campaign, $this->list, null);

    expect($values['UNSUBSCRIBE_URL'])->toBe('#')
        ->and($values['UPDATE_PROFILE_URL'])->toBe('#')
        ->and($values['WEB_VERSION_URL'])->not->toBe('#');
});

it('resolves date tags', function (): void {
    Carbon::setTestNow('2026-08-08 12:00:00');

    $values = $this->engine->values();

    expect($values['CURRENT_YEAR'])->toBe('2026')
        ->and($values['CURRENT_MONTH'])->toBe('08')
        ->and($values['CURRENT_DAY'])->toBe('08')
        ->and($values['CURRENT_DATE'])->toBe('2026-08-08');

    Carbon::setTestNow();
});

it('applies default fallbacks and modifiers, mb-safe', function (): void {
    $values = ['FNAME' => '', 'LNAME' => 'garcía', 'CITY' => '東京'];

    expect($this->engine->render('Hi {FNAME|Friend}!', $values))->toBe('Hi Friend!')
        ->and($this->engine->render('Hi {LNAME:uppercase}', $values))->toBe('Hi GARCÍA')
        ->and($this->engine->render('Hi {LNAME:ucfirst}', $values))->toBe('Hi García')
        ->and($this->engine->render('{LNAME:lowercase}', ['LNAME' => 'GARCÍA']))->toBe('garcía')
        ->and($this->engine->render('{CITY:uppercase}', $values))->toBe('東京')
        ->and($this->engine->render('{FNAME:uppercase|friend}', $values))->toBe('FRIEND')
        ->and($this->engine->render('{UNKNOWN_THING|x}', $values))->toBe('{UNKNOWN_THING|x}');
});

it('never mistakes lowercase spintax groups for merge tags', function (): void {
    $rendered = $this->engine->render('{Hello|Hi} {FNAME|Friend}', ['FNAME' => 'Ann']);

    expect($rendered)->toBe('{Hello|Hi} Ann');
});

it('renders RANDOM_CONTENT deterministically per seed', function (): void {
    $template = 'Pick: {RANDOM_CONTENT:alpha|beta|gamma}';

    $first = $this->engine->render($template, [], 'seed-1');
    $again = $this->engine->render($template, [], 'seed-1');

    expect($first)->toBe($again)
        ->and($first)->toBeIn(['Pick: alpha', 'Pick: beta', 'Pick: gamma']);

    $others = collect(range(1, 30))
        ->map(fn (int $i): string => $this->engine->render($template, [], 'seed-'.$i))
        ->unique();

    expect($others->count())->toBeGreaterThan(1);
});

it('fetches FETCH_URL content with caching', function (): void {
    Http::fake(['content.example.com/*' => Http::response('<b>remote 内容</b>')]);

    $template = 'Now: {FETCH_URL:https://content.example.com/block}';

    expect($this->engine->render($template, []))->toBe('Now: <b>remote 内容</b>');

    // Cached — no second HTTP call within the 5-minute window.
    $this->engine->render($template, []);
    Http::assertSentCount(1);
});

it('fails FETCH_URL empty on errors and timeouts', function (): void {
    Http::fake([
        'down.example.com/*' => Http::response('nope', 500),
        'gone.example.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
    ]);

    expect($this->engine->render('A{FETCH_URL:https://down.example.com/x}B', []))->toBe('AB')
        ->and($this->engine->render('A{FETCH_URL:https://gone.example.com/x}B', []))->toBe('AB');
});

it('scans for broken and unknown tags, honoring the enable list', function (): void {
    $unknown = $this->engine->unknownTags(
        'Hi {FNAME|Friend} {NOT_A_REAL_TAG} {Hello|Hi} {CURRENT_DATE} {LNAME}',
        $this->list,
    );

    expect($unknown)->toBe(['NOT_A_REAL_TAG']);

    // A seeded tag disabled in §3.25.3 becomes unknown again.
    $settings = app(CampaignSettings::class);
    $settings->enabled_template_tags = array_values(array_diff(
        CampaignSettings::KNOWN_TEMPLATE_TAGS,
        ['CAMPAIGN_NAME'],
    ));
    $settings->save();

    expect($this->engine->unknownTags('{CAMPAIGN_NAME}', $this->list))->toBe(['CAMPAIGN_NAME'])
        ->and($this->engine->unknownTags('{CAMPAIGN_SEND_AT}', $this->list))->toBe([]);
});

it('feeds the campaign preflight broken-tag check', function (): void {
    $this->campaign->forceFill([
        'subject' => 'S',
        'content_html' => '<p>{TOTALLY_BROKEN} {FNAME|Friend}</p><p>{UNSUBSCRIBE_URL}</p>',
    ])->saveQuietly();

    $unknown = app(\App\Services\Campaigns\CampaignPreflight::class)->unknownMergeTags($this->campaign->refresh());

    expect($unknown)->toBe(['TOTALLY_BROKEN']);
});

/* ---------------------------------------------------------------- *
 *  F-4.1-010 — conditional blocks [IF …][ELSE][ENDIF]
 *
 *  WP-42: the engine itself shipped with WP-35 as F-2.3.3-008
 *  (App\Services\Content\ConditionalContent, called from MergeTags::render).
 *  These assertions close the §4.1 row specifically — that the conditional
 *  grammar operates on the §4.1 CANONICAL tag set (not only custom fields),
 *  that an untaken branch never reaches tag substitution, and that the
 *  conditional pass composes with §4.1 modifiers and default fallbacks.
 * ---------------------------------------------------------------- */

it('resolves [IF][ELSE][ENDIF] against canonical §4.1 tags (F-4.1-010)', function (): void {
    $values = $this->engine->values($this->campaign, $this->list, $this->subscriber);

    expect($this->engine->render("[IF CAMPAIGN_NAME = 'Summer Sale']sale[ELSE]other[ENDIF]", $values))->toBe('sale')
        ->and($this->engine->render("[IF LIST_FROM_EMAIL ends with 'acme.test']ours[ELSE]theirs[ENDIF]", $values))->toBe('ours')
        ->and($this->engine->render('[IF COMPANY_ZIP is not empty]{COMPANY_ZIP}[ENDIF]', $values))->toBe('62704')
        ->and($this->engine->render("[IF STATUS = 'unsubscribed']gone[ELSE]here[ENDIF]", $values))->toBe('here');
});

it('never substitutes tags inside the branch that was not taken (F-4.1-010)', function (): void {
    $values = $this->engine->values($this->campaign, $this->list, $this->subscriber);

    // FNAME is 太郎 in the fixture; the ELSE branch must vanish whole, so the
    // recipient can never see a half-rendered alternative.
    $rendered = $this->engine->render(
        "[IF FNAME is not empty]Hello {FNAME}![ELSE]Hello {EMAIL} ({CAMPAIGN_UID})![ENDIF]",
        $values,
    );

    expect($rendered)->toBe('Hello 太郎!')
        ->and($rendered)->not->toContain('taro@example.jp')
        ->and($rendered)->not->toContain((string) $this->campaign->uid);
});

it('composes conditionals with modifiers and default fallbacks (F-4.1-010 + F-4.1-007)', function (): void {
    $values = $this->engine->values($this->campaign, $this->list, $this->subscriber);
    $values['NICKNAME'] = '';

    expect($this->engine->render("[IF NICKNAME is empty]{FNAME:uppercase}[ELSE]{NICKNAME}[ENDIF]", $values))->toBe('太郎')
        ->and($this->engine->render("[IF NICKNAME is empty]{NICKNAME|Friend}[ENDIF]", $values))->toBe('Friend');
});

it('flags an [IF] with no [ENDIF] at campaign preflight (F-4.1-010)', function (): void {
    $this->campaign->forceFill([
        'subject' => 'S',
        'content_html' => "<p>[IF FNAME is not empty]Hi {FNAME}</p><p>{UNSUBSCRIBE_URL}</p>",
    ])->saveQuietly();

    $checks = app(\App\Services\Campaigns\CampaignPreflight::class)->checks($this->campaign->refresh());

    $conditional = collect($checks)->firstWhere('key', 'conditional_blocks');

    expect($conditional)->not->toBeNull()
        ->and($conditional['passed'])->toBeFalse();

    $this->campaign->forceFill([
        'content_html' => "<p>[IF FNAME is not empty]Hi {FNAME}[ENDIF]</p><p>{UNSUBSCRIBE_URL}</p>",
    ])->saveQuietly();

    $checks = app(\App\Services\Campaigns\CampaignPreflight::class)->checks($this->campaign->refresh());

    expect(collect($checks)->firstWhere('key', 'conditional_blocks')['passed'])->toBeTrue();
});
