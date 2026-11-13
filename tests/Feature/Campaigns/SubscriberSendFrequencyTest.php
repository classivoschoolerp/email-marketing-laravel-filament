<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Enums\SendFrequency;
use App\Enums\SubscriberStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Campaigns\CampaignRecipients;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Carbon;

/*
 * WP-42 — the send-path half of F-1.5-012 (preference centre).
 *
 * The public page stores `subscribers.send_frequency` /
 * `frequency_paused_until`; this file proves the send path OBEYS them, at the
 * CampaignRecipients choke point, so the wizard count preview, the send wave
 * and the API all agree. Without this the preference would be a form that
 * records a promise nothing keeps.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::Draft,
    ]);
});

afterEach(fn () => app(CurrentCustomer::class)->set(null));

function frequencySubscriber(string $email, ?SendFrequency $frequency, ?Carbon $pausedUntil = null): Subscriber
{
    return Subscriber::factory()->create([
        'customer_id' => test()->customer->id,
        'list_id' => test()->list->id,
        'email' => $email,
        'status' => SubscriberStatus::Confirmed,
        'send_frequency' => $frequency?->value,
        'frequency_paused_until' => $pausedUntil,
    ]);
}

/** Record a delivered campaign for this customer, $daysAgo days back. */
function frequencyPreviousSend(Subscriber $subscriber, int $daysAgo): void
{
    $previous = Campaign::factory()->create([
        'customer_id' => test()->customer->id,
        'email_list_id' => test()->list->id,
        'status' => CampaignStatus::Sent,
    ]);

    CampaignDeliveryLog::query()->create([
        'campaign_id' => $previous->id,
        'subscriber_id' => $subscriber->id,
        'status' => CampaignDeliveryLog::STATUS_SENT,
        'created_at' => Carbon::now()->subDays($daysAgo),
        'updated_at' => Carbon::now()->subDays($daysAgo),
    ]);
}

/** @return list<string> */
function frequencyRecipients(): array
{
    return app(CampaignRecipients::class)
        ->query(test()->campaign)
        ->pluck('subscribers.email')
        ->sort()
        ->values()
        ->all();
}

it('leaves subscribers with no stated preference completely alone (F-1.5-012)', function (): void {
    frequencySubscriber('never-asked@x.test', null);
    frequencySubscriber('every-send@x.test', SendFrequency::EverySend);

    frequencyPreviousSend(Subscriber::query()->where('email', 'never-asked@x.test')->sole(), 1);

    expect(frequencyRecipients())->toBe(['every-send@x.test', 'never-asked@x.test']);
});

it('drops a subscriber whose pause is still running and keeps one whose pause lapsed (F-1.5-012)', function (): void {
    frequencySubscriber('paused@x.test', SendFrequency::Paused, Carbon::now()->addDays(10));
    frequencySubscriber('lapsed@x.test', SendFrequency::Paused, Carbon::now()->subDay());
    frequencySubscriber('indefinite@x.test', SendFrequency::Paused, null);
    frequencySubscriber('active@x.test', SendFrequency::EverySend);

    expect(frequencyRecipients())->toBe(['active@x.test', 'lapsed@x.test']);
});

it('throttles a weekly subscriber to one campaign a week (F-1.5-012)', function (): void {
    $recent = frequencySubscriber('weekly-recent@x.test', SendFrequency::Weekly);
    $old = frequencySubscriber('weekly-old@x.test', SendFrequency::Weekly);
    frequencySubscriber('weekly-fresh@x.test', SendFrequency::Weekly);

    frequencyPreviousSend($recent, 6);   // inside the 7-day window → skipped
    frequencyPreviousSend($old, 8);      // outside it → mailed

    expect(frequencyRecipients())->toBe(['weekly-fresh@x.test', 'weekly-old@x.test']);
});

it('throttles a monthly subscriber on the longer window (F-1.5-012)', function (): void {
    $recent = frequencySubscriber('monthly-recent@x.test', SendFrequency::Monthly);
    $old = frequencySubscriber('monthly-old@x.test', SendFrequency::Monthly);
    $weekly = frequencySubscriber('weekly@x.test', SendFrequency::Weekly);

    // 20 days ago: inside a monthly window, outside a weekly one.
    frequencyPreviousSend($recent, 20);
    frequencyPreviousSend($old, 31);
    frequencyPreviousSend($weekly, 20);

    expect(frequencyRecipients())->toBe(['monthly-old@x.test', 'weekly@x.test']);
});

it('counts only this customers own delivered sends (F-1.5-012)', function (): void {
    $subscriber = frequencySubscriber('weekly@x.test', SendFrequency::Weekly);

    // A FAILED delivery is not a send the recipient ever saw.
    $failedCampaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::Sent,
    ]);

    CampaignDeliveryLog::query()->create([
        'campaign_id' => $failedCampaign->id,
        'subscriber_id' => $subscriber->id,
        'status' => CampaignDeliveryLog::STATUS_FAILED,
        'created_at' => Carbon::now()->subDay(),
        'updated_at' => Carbon::now()->subDay(),
    ]);

    expect(frequencyRecipients())->toBe(['weekly@x.test']);

    // A real send inside the window does throttle.
    frequencyPreviousSend($subscriber, 1);

    expect(frequencyRecipients())->toBe([]);
});

it('never counts the campaign being sent against its own recipients (F-1.5-012)', function (): void {
    $subscriber = frequencySubscriber('weekly@x.test', SendFrequency::Weekly);

    // A partially delivered run of THIS campaign must not exclude the rest.
    CampaignDeliveryLog::query()->create([
        'campaign_id' => $this->campaign->id,
        'subscriber_id' => $subscriber->id,
        'status' => CampaignDeliveryLog::STATUS_SENT,
        'created_at' => Carbon::now()->subMinute(),
        'updated_at' => Carbon::now()->subMinute(),
    ]);

    expect(frequencyRecipients())->toBe(['weekly@x.test']);
});

it('keeps the count preview and the recipient query in step (F-1.5-012)', function (): void {
    frequencySubscriber('paused@x.test', SendFrequency::Paused, Carbon::now()->addDay());
    frequencySubscriber('ok@x.test', SendFrequency::EverySend);

    expect(app(CampaignRecipients::class)->count($this->campaign))->toBe(1)
        ->and(frequencyRecipients())->toBe(['ok@x.test']);
});
