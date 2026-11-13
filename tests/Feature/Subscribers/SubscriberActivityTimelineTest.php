<?php

declare(strict_types=1);

use App\Enums\BounceType;
use App\Filament\Customer\Resources\SubscriberResource;
use App\Models\Campaign;
use App\Models\CampaignBounceLog;
use App\Models\CampaignComplaintLog;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUnsubscribe;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Subscribers\SubscriberActivityTimeline;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

/**
 * F-2.2.5-005 — the subscriber activity timeline.
 *
 * Fixture, one tenant, one subscriber, two campaigns:
 *
 *   2026-07-01 10:00  delivery of "Spring Sale"        (status sent)
 *   2026-07-01 11:00  open of "Spring Sale"
 *   2026-07-02 09:00  open of "Spring Sale"
 *   2026-07-02 09:30  click of "Spring Sale" → /offer
 *   2026-07-03 08:00  hard bounce of "Autumn Sale"
 *   2026-07-04 08:00  spam complaint of "Autumn Sale"
 *   2026-07-05 08:00  unsubscribe from "Spring Sale"
 *
 * plus one open row belonging to ANOTHER tenant's campaign but carrying this
 * subscriber's uid — the uid-keyed tracking tables have no customer column,
 * so that row is the leak this feature has to refuse.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->subscriber = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'timeline@x.example']);

    $this->spring = Campaign::factory()->for($this->customer)->create([
        'email_list_id' => $this->list->id,
        'name' => 'Spring Sale',
    ]);
    $this->autumn = Campaign::factory()->for($this->customer)->create([
        'email_list_id' => $this->list->id,
        'name' => 'Autumn Sale',
    ]);

    $this->url = CampaignTrackUrl::create([
        'campaign_id' => $this->spring->id,
        'campaign_uid' => $this->spring->uid,
        'url' => 'https://example.test/offer',
        'hash' => str_repeat('a', 64),
    ]);

    CampaignDeliveryLog::create([
        'campaign_id' => $this->spring->id,
        'subscriber_id' => $this->subscriber->id,
        'status' => CampaignDeliveryLog::STATUS_SENT,
        'created_at' => Carbon::parse('2026-07-01 10:00:00'),
        'updated_at' => Carbon::parse('2026-07-01 10:00:00'),
    ]);

    CampaignTrackOpen::create([
        'campaign_id' => $this->spring->id,
        'campaign_uid' => $this->spring->uid,
        'subscriber_uid' => $this->subscriber->uid,
        'created_at' => Carbon::parse('2026-07-01 11:00:00'),
    ]);

    CampaignTrackOpen::create([
        'campaign_id' => $this->spring->id,
        'campaign_uid' => $this->spring->uid,
        'subscriber_uid' => $this->subscriber->uid,
        'created_at' => Carbon::parse('2026-07-02 09:00:00'),
    ]);

    CampaignTrackClick::create([
        'campaign_id' => $this->spring->id,
        'campaign_uid' => $this->spring->uid,
        'subscriber_uid' => $this->subscriber->uid,
        'url_id' => $this->url->id,
        'created_at' => Carbon::parse('2026-07-02 09:30:00'),
    ]);

    CampaignBounceLog::create([
        'customer_id' => $this->customer->id,
        'campaign_id' => $this->autumn->id,
        'subscriber_id' => $this->subscriber->id,
        'bounce_type' => BounceType::Hard->value,
        'email' => $this->subscriber->email,
        'created_at' => Carbon::parse('2026-07-03 08:00:00'),
    ]);

    CampaignComplaintLog::create([
        'customer_id' => $this->customer->id,
        'campaign_id' => $this->autumn->id,
        'subscriber_id' => $this->subscriber->id,
        'feedback_type' => 'abuse',
        'email' => $this->subscriber->email,
        'created_at' => Carbon::parse('2026-07-04 08:00:00'),
    ]);

    CampaignTrackUnsubscribe::create([
        'campaign_id' => $this->spring->id,
        'campaign_uid' => $this->spring->uid,
        'subscriber_uid' => $this->subscriber->uid,
        'created_at' => Carbon::parse('2026-07-05 08:00:00'),
    ]);

    // Another tenant's campaign, our subscriber's uid.
    $this->foreignCampaign = Campaign::factory()->create();

    CampaignTrackOpen::create([
        'campaign_id' => $this->foreignCampaign->id,
        'campaign_uid' => $this->foreignCampaign->uid,
        'subscriber_uid' => $this->subscriber->uid,
        'created_at' => Carbon::parse('2026-07-06 08:00:00'),
    ]);

    $this->timeline = app(SubscriberActivityTimeline::class);
});

it('merges every tracked event into one reverse-chronological stream (F-2.2.5-005)', function (): void {
    $events = $this->timeline->events($this->subscriber);

    expect($events)->toHaveCount(7)
        ->and(array_column($events, 'type'))->toBe([
            SubscriberActivityTimeline::TYPE_UNSUBSCRIBE,
            SubscriberActivityTimeline::TYPE_COMPLAINT,
            SubscriberActivityTimeline::TYPE_BOUNCE,
            SubscriberActivityTimeline::TYPE_CLICK,
            SubscriberActivityTimeline::TYPE_OPEN,
            SubscriberActivityTimeline::TYPE_OPEN,
            SubscriberActivityTimeline::TYPE_DELIVERY,
        ])
        ->and(array_map(fn (array $e): string => $e['at']->format('Y-m-d H:i'), $events))->toBe([
            '2026-07-05 08:00',
            '2026-07-04 08:00',
            '2026-07-03 08:00',
            '2026-07-02 09:30',
            '2026-07-02 09:00',
            '2026-07-01 11:00',
            '2026-07-01 10:00',
        ]);
});

it('names the campaign behind every event and the URL behind every click (F-2.2.5-005)', function (): void {
    $events = $this->timeline->events($this->subscriber);

    expect(array_column($events, 'campaign_name'))->toBe([
        'Spring Sale',   // unsubscribe
        'Autumn Sale',   // complaint
        'Autumn Sale',   // bounce
        'Spring Sale',   // click
        'Spring Sale',   // open
        'Spring Sale',   // open
        'Spring Sale',   // delivery
    ]);

    $click = $events[3];
    $bounce = $events[2];
    $complaint = $events[1];
    $delivery = $events[6];

    expect($click['detail'])->toBe('https://example.test/offer')
        ->and($click['campaign_id'])->toBe((int) $this->spring->id)
        ->and($bounce['detail'])->toBe(BounceType::Hard->label())
        ->and($complaint['detail'])->toBe('abuse')
        ->and($delivery['detail'])->toBe(__('subscribers.timeline.delivery_status.sent'));
});

it('counts true totals in the timeline summary (F-2.2.5-005)', function (): void {
    expect($this->timeline->summary($this->subscriber))->toBe([
        'delivered' => 1,
        'opens' => 2,
        'clicks' => 1,
        'unsubscribes' => 1,
        'bounces' => 1,
        'complaints' => 1,
    ]);
});

it('never shows a tracking row that belongs to another tenant\'s campaign (F-2.2.5-005)', function (): void {
    $events = $this->timeline->events($this->subscriber);

    // The 2026-07-06 open on the foreign campaign would sort first if it leaked.
    expect(array_column($events, 'campaign_name'))->not->toContain($this->foreignCampaign->name)
        ->and($events[0]['at']->format('Y-m-d'))->toBe('2026-07-05')
        ->and($this->timeline->summary($this->subscriber)['opens'])->toBe(2);
});

it('orders events sharing an instant by lifecycle position (F-2.2.5-005)', function (): void {
    $at = Carbon::parse('2026-07-10 12:00:00');

    CampaignTrackOpen::create([
        'campaign_id' => $this->spring->id,
        'campaign_uid' => $this->spring->uid,
        'subscriber_uid' => $this->subscriber->uid,
        'created_at' => $at,
    ]);

    CampaignTrackClick::create([
        'campaign_id' => $this->spring->id,
        'campaign_uid' => $this->spring->uid,
        'subscriber_uid' => $this->subscriber->uid,
        'url_id' => $this->url->id,
        'created_at' => $at,
    ]);

    CampaignDeliveryLog::create([
        'campaign_id' => $this->autumn->id,
        'subscriber_id' => $this->subscriber->id,
        'status' => CampaignDeliveryLog::STATUS_SENT,
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    $events = $this->timeline->events($this->subscriber);

    expect(array_slice(array_column($events, 'type'), 0, 3))->toBe([
        SubscriberActivityTimeline::TYPE_CLICK,
        SubscriberActivityTimeline::TYPE_OPEN,
        SubscriberActivityTimeline::TYPE_DELIVERY,
    ]);
});

it('honours the row limit newest-first (F-2.2.5-005)', function (): void {
    $events = $this->timeline->events($this->subscriber, 3);

    expect($events)->toHaveCount(3)
        ->and(array_column($events, 'type'))->toBe([
            SubscriberActivityTimeline::TYPE_UNSUBSCRIBE,
            SubscriberActivityTimeline::TYPE_COMPLAINT,
            SubscriberActivityTimeline::TYPE_BOUNCE,
        ]);
});

it('renders the timeline on the profile page (F-2.2.5-005)', function (): void {
    $this->get(SubscriberResource::getUrl('view', ['record' => $this->subscriber], panel: 'customer'))
        ->assertOk()
        ->assertSee(__('subscribers.profile.timeline_heading'))
        ->assertSee('Spring Sale')
        ->assertSee('Autumn Sale')
        ->assertSee('https://example.test/offer')
        ->assertSee(__('subscribers.timeline.types.click'))
        ->assertDontSee(__('subscribers.profile.timeline_placeholder'));
});
