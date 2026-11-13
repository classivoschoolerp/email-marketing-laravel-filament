<?php

declare(strict_types=1);

use App\Enums\OptInType;
use App\Enums\SubscriberStatus;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUrl;
use App\Models\EmailList;
use App\Models\LoginLog;
use App\Models\Subscriber;
use App\Services\Tracking\UrlSigner;
use App\Settings\PrivacySettings;
use App\Support\Privacy\IpAnonymizer;
use Illuminate\Support\Facades\URL;

/*
 * WP-22 — F-3.25.16-005 / F-4.5-009: anonymised analytics. Both modes are
 * proved at every capture point that WP-22 wires.
 */

function enableAnonymization(): void
{
    $settings = app(PrivacySettings::class);
    $settings->anonymize_ips = true;
    $settings->save();
}

it('truncates ipv4 and ipv6 host portions', function (): void {
    expect(IpAnonymizer::anonymize('203.0.113.77'))->toBe('203.0.113.0')
        ->and(IpAnonymizer::anonymize('2001:db8:1:2:3:4:5:6'))->toBe('2001:db8:1::')
        ->and(IpAnonymizer::anonymize('::1'))->toBe('::')
        ->and(IpAnonymizer::anonymize(null))->toBeNull()
        // Anything that is not an IP is never echoed back.
        ->and(IpAnonymizer::anonymize('not-an-ip'))->toBeNull();
});

it('passes IPs through untouched while the setting is off', function (): void {
    expect(IpAnonymizer::maybe('203.0.113.77'))->toBe('203.0.113.77');

    enableAnonymization();

    expect(IpAnonymizer::maybe('203.0.113.77'))->toBe('203.0.113.0');
});

// F-4.5-009 — opens
it('anonymises the open-tracking IP at capture time', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
        ->get(route('campaigns.track.open', ['campaign_uid' => 'c1', 'subscriber_uid' => 's1']))
        ->assertOk();

    expect(CampaignTrackOpen::query()->value('ip'))->toBe('203.0.113.77');

    enableAnonymization();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
        ->get(route('campaigns.track.open', ['campaign_uid' => 'c2', 'subscriber_uid' => 's2']))
        ->assertOk();

    expect(CampaignTrackOpen::query()->where('campaign_uid', 'c2')->value('ip'))->toBe('203.0.113.0');
});

// F-4.5-009 — clicks
it('anonymises the click-tracking IP at capture time', function (): void {
    enableAnonymization();

    $url = 'https://example.com/offer';
    $hash = app(UrlSigner::class)->hash($url);

    CampaignTrackUrl::query()->create([
        'campaign_uid' => 'c1',
        'url' => $url,
        'hash' => $hash,
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
        ->get(route('campaigns.track.click', [
            'campaign_uid' => 'c1',
            'subscriber_uid' => 's1',
            'url_hash' => $hash,
        ]))
        ->assertRedirect($url);

    expect(CampaignTrackClick::query()->value('ip'))->toBe('198.51.100.0');
});

// F-3.25.16-005 — subscribe / confirm capture points
it('anonymises the subscribe and confirm proof IPs', function (): void {
    enableAnonymization();

    $list = EmailList::factory()->create([
        'visible' => true,
        'status' => EmailList::STATUS_ACTIVE,
        'opt_in' => OptInType::Double,
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
        ->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), [
            'email' => 'anon@example.com',
            'hosted' => 1,
        ]);

    $subscriber = Subscriber::withoutTenancy()->where('email', 'anon@example.com')->firstOrFail();

    expect($subscriber->ip)->toBe('203.0.113.0')
        ->and($subscriber->subscribe_ip)->toBe('203.0.113.0')
        ->and($subscriber->status)->toBe(SubscriberStatus::Unconfirmed);

    $confirmUrl = URL::signedRoute('lists.public.confirm', [
        'list_uid' => $list->uid,
        'subscriber_uid' => $subscriber->uid,
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->get($confirmUrl)->assertOk();

    expect($subscriber->fresh()->confirm_ip)->toBe('198.51.100.0');
});

// F-3.25.16-005 — unsubscribe capture point
it('anonymises the unsubscribe IP', function (): void {
    enableAnonymization();

    $list = EmailList::factory()->create(['visible' => true, 'status' => EmailList::STATUS_ACTIVE]);
    $subscriber = Subscriber::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'status' => SubscriberStatus::Confirmed,
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.201'])
        ->post(route('lists.public.unsubscribe.one_click', [
            'list_uid' => $list->uid,
            'subscriber_uid' => $subscriber->uid,
        ]), ['List-Unsubscribe' => 'One-Click'])
        ->assertOk();

    expect($subscriber->fresh()->unsubscribe_ip)->toBe('203.0.113.0');
});

// F-3.25.16-005 — the sign-in audit trail
it('anonymises login log IPs', function (): void {
    enableAnonymization();

    $request = \Illuminate\Http\Request::create('/customer/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.31']);

    LoginLog::record('customer', null, 'someone@example.com', \App\Enums\LoginResult::Failed, $request);

    expect(LoginLog::query()->value('ip'))->toBe('203.0.113.0');
});
