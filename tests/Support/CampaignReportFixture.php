<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Campaign;
use App\Models\CampaignBounceLog;
use App\Models\CampaignComplaintLog;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignForward;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUnsubscribe;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\Subscriber;
use Illuminate\Support\Carbon;

/**
 * WP-17 shared report fixture. Seeds one campaign with a fully known event
 * history so report tests assert EXACT numbers:
 *
 * recipients (counter)     5
 * delivery logs            s1..s4 sent (s1 via server, message id/response), s5 failed (retries 2)
 * opens                    s1×3 (2 today, 1 yesterday; desktop/Windows/Chrome/Gmail),
 *                          s2×1 (today; mobile/iOS/Safari/Apple Mail) → 4 total, 2 unique
 * urls + clicks            url A: s1×2 today; url B: s1×1 today → 3 total, 1 unique
 * unsubscribes             s2 (ip 9.9.9.9) → 1
 * bounces                  s5 hard, s3 soft, unattributed internal → 3 (1/1/1)
 * complaints               s4 (feedback abuse) → 1
 * forwards                 s1 ×2, s2 ×1 → 3 forwards, 2 distinct forwarders
 *
 * Rates: open 2/4=50.0, click 1/4=25.0, CTOR 1/2=50.0.
 */
final class CampaignReportFixture
{
    public Customer $customer;

    public EmailList $list;

    public Campaign $campaign;

    public DeliveryServer $server;

    /** @var array<int, Subscriber> s1..s5 */
    public array $subscribers = [];

    public CampaignTrackUrl $urlA;

    public CampaignTrackUrl $urlB;

    public static function build(?Customer $customer = null): self
    {
        // Pin "now" to midday so relative event times (now-1h, now-2h,
        // yesterday 09:00) never straddle a midnight boundary and the
        // daily/hourly bucket assertions stay exact at any wall-clock time.
        // Laravel's TestCase resets the Carbon test-now in tearDown.
        Carbon::setTestNow(Carbon::now()->startOfDay()->addHours(12));

        $fixture = new self;

        $fixture->customer = $customer ?? Customer::factory()->create();
        $fixture->list = EmailList::factory()->create(['customer_id' => $fixture->customer->id]);

        $fixture->campaign = Campaign::factory()->sent()->create([
            'customer_id' => $fixture->customer->id,
            'email_list_id' => $fixture->list->id,
            'delivery_total' => 5,
            'delivery_sent' => 4,
            'delivery_failed' => 1,
            'send_started_at' => now()->subDay(),
        ]);

        $fixture->server = DeliveryServer::factory()->create([
            'customer_id' => $fixture->customer->id,
            'name' => 'Fixture SMTP',
        ]);

        foreach ([1, 2, 3, 4, 5] as $i) {
            $fixture->subscribers[$i] = Subscriber::factory()->create([
                'list_id' => $fixture->list->id,
                'customer_id' => $fixture->customer->id,
                'email' => 's'.$i.'@fixture.test',
            ]);
        }

        $campaign = $fixture->campaign;
        $subs = $fixture->subscribers;

        // Delivery logs: s1..s4 sent, s5 failed.
        foreach ([1, 2, 3, 4] as $i) {
            CampaignDeliveryLog::query()->create([
                'campaign_id' => $campaign->id,
                'subscriber_id' => $subs[$i]->id,
                'delivery_server_id' => $i === 1 ? $fixture->server->id : null,
                'status' => CampaignDeliveryLog::STATUS_SENT,
                'message_id' => $i === 1 ? 'msg-id-0001' : null,
                'response' => $i === 1 ? '250 OK' : null,
                'retries' => 0,
            ]);
        }

        CampaignDeliveryLog::query()->create([
            'campaign_id' => $campaign->id,
            'subscriber_id' => $subs[5]->id,
            'delivery_server_id' => $fixture->server->id,
            'status' => CampaignDeliveryLog::STATUS_FAILED,
            'message_id' => null,
            'response' => 'SMTP 550 mailbox unavailable',
            'retries' => 2,
        ]);

        // Opens: s1×3 (2 today, 1 yesterday), s2×1 (today).
        $open = fn (int $i, $at, array $ua) => CampaignTrackOpen::query()->create(array_merge([
            'campaign_id' => $campaign->id,
            'campaign_uid' => $campaign->uid,
            'subscriber_uid' => $subs[$i]->uid,
            'ip' => '1.2.3.'.$i,
            'created_at' => $at,
        ], $ua));

        $desktop = ['device' => 'desktop', 'os' => 'Windows', 'browser' => 'Chrome', 'client' => 'Gmail'];
        $mobile = ['device' => 'mobile', 'os' => 'iOS', 'browser' => 'Safari', 'client' => 'Apple Mail'];

        $open(1, now()->subDay()->setTime(9, 0), $desktop);
        $open(1, now()->subHours(2), $desktop);
        $open(1, now()->subHour(), $desktop);
        $open(2, now()->subHour(), $mobile);

        // Tracked URLs + clicks: url A s1×2, url B s1×1 (today).
        $fixture->urlA = CampaignTrackUrl::query()->create([
            'campaign_id' => $campaign->id,
            'campaign_uid' => $campaign->uid,
            'url' => 'https://example.com/a',
            'hash' => str_repeat('a', 64),
        ]);

        $fixture->urlB = CampaignTrackUrl::query()->create([
            'campaign_id' => $campaign->id,
            'campaign_uid' => $campaign->uid,
            'url' => 'https://example.com/b',
            'hash' => str_repeat('b', 64),
        ]);

        $click = fn (CampaignTrackUrl $url, int $i, $at) => CampaignTrackClick::query()->create([
            'campaign_id' => $campaign->id,
            'campaign_uid' => $campaign->uid,
            'subscriber_uid' => $subs[$i]->uid,
            'url_id' => $url->id,
            'ip' => '1.2.3.'.$i,
            'device' => 'desktop',
            'created_at' => $at,
        ]);

        $click($fixture->urlA, 1, now()->subHours(3));
        $click($fixture->urlA, 1, now()->subHour());
        $click($fixture->urlB, 1, now()->subHour());

        // Unsubscribe: s2.
        CampaignTrackUnsubscribe::query()->create([
            'campaign_id' => $campaign->id,
            'campaign_uid' => $campaign->uid,
            'subscriber_uid' => $subs[2]->uid,
            'ip' => '9.9.9.9',
            'created_at' => now()->subMinutes(30),
        ]);

        $subs[2]->forceFill(['unsubscribe_reason' => 'Too many emails'])->save();

        // Bounces: s5 hard, s3 soft, one unattributed internal.
        CampaignBounceLog::query()->create([
            'customer_id' => $fixture->customer->id,
            'campaign_id' => $campaign->id,
            'subscriber_id' => $subs[5]->id,
            'bounce_type' => 'hard',
            'email' => $subs[5]->email,
            'message' => '550 5.1.1 user unknown',
        ]);

        CampaignBounceLog::query()->create([
            'customer_id' => $fixture->customer->id,
            'campaign_id' => $campaign->id,
            'subscriber_id' => $subs[3]->id,
            'bounce_type' => 'soft',
            'email' => $subs[3]->email,
            'message' => '452 mailbox full',
        ]);

        CampaignBounceLog::query()->create([
            'campaign_id' => $campaign->id,
            'bounce_type' => 'internal',
            'message' => 'unattributed DSN',
        ]);

        // WP-35 (§1.6 F-1.6-002 / §1.7 F-1.7-004) forwards:
        // s1 → 2 friends, s2 → 1 friend ⇒ 3 forwards, 2 distinct forwarders.
        foreach ([[1, 'friend-a@fixture.test'], [1, 'friend-b@fixture.test'], [2, 'friend-c@fixture.test']] as [$i, $email]) {
            CampaignForward::query()->create([
                'campaign_id' => $campaign->id,
                'campaign_uid' => $campaign->uid,
                'subscriber_uid' => $subs[$i]->uid,
                'recipient_email' => $email,
                'status' => CampaignForward::STATUS_SENT,
                'created_at' => now()->subMinutes(20),
                'sent_at' => now()->subMinutes(20),
            ]);
        }

        // Complaint: s4.
        CampaignComplaintLog::query()->create([
            'customer_id' => $fixture->customer->id,
            'campaign_id' => $campaign->id,
            'subscriber_id' => $subs[4]->id,
            'fbl_server_id' => null,
            'feedback_type' => 'abuse',
            'email' => $subs[4]->email,
            'message' => 'ARF report',
        ]);

        return $fixture;
    }
}
