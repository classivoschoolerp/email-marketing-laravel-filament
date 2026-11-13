<?php

declare(strict_types=1);

use App\Enums\BounceType;
use App\Filament\Customer\Resources\SubscriberResource;
use App\Models\Campaign;
use App\Models\CampaignBounceLog;
use App\Models\CampaignComplaintLog;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUnsubscribe;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Subscribers\EngagementScorer;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

/**
 * F-2.2.5-006 — engagement score / rating.
 *
 * Every number below is computed by hand from the formula documented on
 * App\Services\Subscribers\EngagementScorer, so a change to the weights
 * breaks these tests on purpose.
 */
beforeEach(function (): void {
    $this->now = Carbon::parse('2026-07-01 12:00:00');
    // Freeze the clock so the profile page (which scores against the real
    // "now") sees the same ages as the direct service calls.
    Carbon::setTestNow($this->now);

    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->subscriber = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'scored@x.example']);

    $this->campaign = Campaign::factory()->for($this->customer)->create(['email_list_id' => $this->list->id]);

    $this->url = CampaignTrackUrl::create([
        'campaign_id' => $this->campaign->id,
        'campaign_uid' => $this->campaign->uid,
        'url' => 'https://example.test/offer',
        'hash' => str_repeat('b', 64),
    ]);

    $this->open = function (int $daysAgo, int $times = 1): void {
        for ($i = 0; $i < $times; $i++) {
            CampaignTrackOpen::create([
                'campaign_id' => $this->campaign->id,
                'campaign_uid' => $this->campaign->uid,
                'subscriber_uid' => $this->subscriber->uid,
                'created_at' => $this->now->copy()->subDays($daysAgo),
            ]);
        }
    };

    $this->click = function (int $daysAgo, int $times = 1): void {
        for ($i = 0; $i < $times; $i++) {
            CampaignTrackClick::create([
                'campaign_id' => $this->campaign->id,
                'campaign_uid' => $this->campaign->uid,
                'subscriber_uid' => $this->subscriber->uid,
                'url_id' => $this->url->id,
                'created_at' => $this->now->copy()->subDays($daysAgo),
            ]);
        }
    };

    $this->scoreNow = fn (): array => app(EngagementScorer::class)->score($this->subscriber, $this->now);
});

it('scores a subscriber with no tracked activity as inactive (F-2.2.5-006)', function (): void {
    expect(($this->scoreNow)())->toMatchArray([
        'score' => 0,
        'rating' => EngagementScorer::RATING_INACTIVE,
        'opens' => 0,
        'clicks' => 0,
        'bounces' => 0,
        'complaints' => 0,
        'unsubscribes' => 0,
    ]);
});

it('weights opens and clicks by recency (F-2.2.5-006)', function (): void {
    // Opens: 3 × 1.0 + 2 × 0.6 + 1 × 0.3 + 1 × 0.1 = 4.6 weighted (1 dropped).
    ($this->open)(1, 3);
    ($this->open)(60, 2);
    ($this->open)(120);
    ($this->open)(300);
    ($this->open)(400);

    // Clicks: 2 × 1.0 + 1 × 0.3 = 2.3 weighted.
    ($this->click)(1, 2);
    ($this->click)(100);

    $result = ($this->scoreNow)();

    // points = 2 × 4.6 + 5 × 2.3 = 9.2 + 11.5 = 20.7 → 21.
    expect($result['score'])->toBe(21)
        ->and($result['rating'])->toBe(EngagementScorer::RATING_LOW)
        ->and($result['opens'])->toBe(7)          // the 400-day-old open is outside the window
        ->and($result['clicks'])->toBe(3)
        ->and($result['weighted_opens'])->toEqualWithDelta(4.6, 0.0001)
        ->and($result['weighted_clicks'])->toEqualWithDelta(2.3, 0.0001)
        ->and($result['points'])->toEqualWithDelta(20.7, 0.0001);
});

it('rounds half a point away from zero (F-2.2.5-006)', function (): void {
    // A single 100-day-old click: 5 × 0.3 = 1.5 points → 2.
    ($this->click)(100);

    expect(($this->scoreNow)()['score'])->toBe(2);
});

it('subtracts bounces, complaints and unsubscribes and floors the score at zero (F-2.2.5-006)', function (): void {
    ($this->open)(1, 10);   // 10 weighted opens → 20 points
    ($this->click)(1, 4);   //  4 weighted clicks → 20 points  ⇒ 40

    CampaignBounceLog::create([
        'customer_id' => $this->customer->id,
        'campaign_id' => $this->campaign->id,
        'subscriber_id' => $this->subscriber->id,
        'bounce_type' => BounceType::Soft->value,
        'created_at' => $this->now->copy()->subDays(5),
    ]);

    // 40 − 10 = 30 → the bottom of "medium".
    expect(($this->scoreNow)())->toMatchArray([
        'score' => 30,
        'rating' => EngagementScorer::RATING_MEDIUM,
        'bounces' => 1,
    ]);

    CampaignComplaintLog::create([
        'customer_id' => $this->customer->id,
        'campaign_id' => $this->campaign->id,
        'subscriber_id' => $this->subscriber->id,
        'feedback_type' => 'abuse',
        'created_at' => $this->now->copy()->subDays(4),
    ]);

    // 30 − 25 = 5.
    expect(($this->scoreNow)()['score'])->toBe(5);

    CampaignTrackUnsubscribe::create([
        'campaign_id' => $this->campaign->id,
        'campaign_uid' => $this->campaign->uid,
        'subscriber_uid' => $this->subscriber->uid,
        'created_at' => $this->now->copy()->subDays(3),
    ]);

    // 5 − 20 = −15, floored at 0.
    expect(($this->scoreNow)())->toMatchArray([
        'score' => 0,
        'rating' => EngagementScorer::RATING_INACTIVE,
        'unsubscribes' => 1,
        'complaints' => 1,
    ]);
});

it('caps a hyperactive subscriber at 100 and rates them high (F-2.2.5-006)', function (): void {
    ($this->open)(2, 40);   // 80 points
    ($this->click)(2, 30);  // 150 points ⇒ 230 raw

    expect(($this->scoreNow)())->toMatchArray([
        'score' => 100,
        'rating' => EngagementScorer::RATING_HIGH,
    ]);
});

it('maps every score band to its rating (F-2.2.5-006)', function (): void {
    expect(EngagementScorer::ratingFor(0))->toBe(EngagementScorer::RATING_INACTIVE)
        ->and(EngagementScorer::ratingFor(1))->toBe(EngagementScorer::RATING_LOW)
        ->and(EngagementScorer::ratingFor(29))->toBe(EngagementScorer::RATING_LOW)
        ->and(EngagementScorer::ratingFor(30))->toBe(EngagementScorer::RATING_MEDIUM)
        ->and(EngagementScorer::ratingFor(69))->toBe(EngagementScorer::RATING_MEDIUM)
        ->and(EngagementScorer::ratingFor(70))->toBe(EngagementScorer::RATING_HIGH)
        ->and(EngagementScorer::ratingFor(100))->toBe(EngagementScorer::RATING_HIGH);
});

it('never scores another tenant\'s tracking rows (F-2.2.5-006)', function (): void {
    ($this->open)(1, 5);   // 5 weighted opens → 10 points

    $foreignCampaign = Campaign::factory()->create();

    // Same subscriber uid, a campaign this tenant does not own.
    foreach (range(1, 20) as $ignored) {
        CampaignTrackOpen::create([
            'campaign_id' => $foreignCampaign->id,
            'campaign_uid' => $foreignCampaign->uid,
            'subscriber_uid' => $this->subscriber->uid,
            'created_at' => $this->now->copy()->subDay(),
        ]);
    }

    expect(($this->scoreNow)())->toMatchArray([
        'score' => 10,
        'opens' => 5,
        'rating' => EngagementScorer::RATING_LOW,
    ]);
});

it('shows the score and rating on the profile page (F-2.2.5-006)', function (): void {
    ($this->open)(1, 10);
    ($this->click)(1, 4);

    $this->get(SubscriberResource::getUrl('view', ['record' => $this->subscriber], panel: 'customer'))
        ->assertOk()
        ->assertSee(__('subscribers.engagement_score.heading'))
        ->assertSee(__('subscribers.engagement_score.rating.medium'));
});
