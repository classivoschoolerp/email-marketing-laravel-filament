<?php

declare(strict_types=1);

use App\Enums\AutoresponderTrigger;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\ListFieldType;
use App\Enums\SubscriberStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackOpen;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Sending\DriverRegistry;
use Illuminate\Support\Carbon;
use Tests\Fakes\RecordingSendingDriver;

/*
 * §0.6 F-0.6-002 — campaigns:process-autoresponders, one test per trigger
 * with time travel (F-2.3.2-014..017).
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-08 10:00:00');

    $this->list = EmailList::factory()->create();

    $this->server = DeliveryServer::factory()->create([
        'customer_id' => $this->list->customer_id,
        'type' => 'smtp',
    ]);

    $this->driver = new RecordingSendingDriver('smtp');
    app(DriverRegistry::class)->register('smtp', $this->driver);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function autoresponder(EmailList $list, array $attributes = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'customer_id' => $list->customer_id,
        'email_list_id' => $list->id,
        'type' => CampaignType::Autoresponder,
        'status' => CampaignStatus::Scheduled,
        'ar_trigger' => AutoresponderTrigger::AfterSubscribe,
        'ar_delay_value' => 0,
        'ar_delay_unit' => 'minutes',
        'ar_apply_to' => 'existing',
        'ar_once_per_subscriber' => true,
    ], $attributes));
}

it('sends after-subscribe once the delay elapses, and only once per subscriber', function (): void {
    $campaign = autoresponder($this->list, ['ar_delay_value' => 2, 'ar_delay_unit' => 'hours']);

    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'confirmed_at' => Carbon::now(),
    ]);

    // Not due yet — the 2h delay has not elapsed.
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();
    expect($this->driver->sent)->toHaveCount(0)
        ->and($campaign->refresh()->status)->toBe(CampaignStatus::Sending); // activated

    // Travel past the delay → due → sent.
    Carbon::setTestNow('2026-08-08 12:30:00');
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();

    expect($this->driver->sent)->toHaveCount(1)
        ->and($this->driver->sent[0]['message']->firstRecipient())->toBe($subscriber->email)
        ->and(CampaignDeliveryLog::query()->where('campaign_id', $campaign->id)->where('status', 'sent')->count())->toBe(1);

    // once_per_subscriber: the next tick never re-sends.
    Carbon::setTestNow('2026-08-08 14:00:00');
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();
    expect($this->driver->sent)->toHaveCount(1);
});

it('applies ar_apply_to: new skips members who joined before activation', function (): void {
    $existing = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'subscribed_at' => Carbon::now()->subDay(),
        'confirmed_at' => Carbon::now()->subDay(),
    ]);

    $campaign = autoresponder($this->list, ['ar_apply_to' => 'new']);

    // Activation tick.
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();

    // The pre-existing member is never targeted.
    Carbon::setTestNow('2026-08-08 11:00:00');
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();
    expect($this->driver->sent)->toHaveCount(0);

    // A member joining after activation is.
    $fresh = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'subscribed_at' => Carbon::now(),
        'confirmed_at' => Carbon::now(),
    ]);

    Carbon::setTestNow('2026-08-08 11:30:00');
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();

    expect($this->driver->sent)->toHaveCount(1)
        ->and($this->driver->sent[0]['message']->firstRecipient())->toBe($fresh->email);
});

it('sends after-unsubscribe to just-unsubscribed members', function (): void {
    $campaign = autoresponder($this->list, [
        'ar_trigger' => AutoresponderTrigger::AfterUnsubscribe,
        'ar_delay_value' => 30,
        'ar_delay_unit' => 'minutes',
    ]);

    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'status' => SubscriberStatus::Unsubscribed,
        'unsubscribed_at' => Carbon::now(),
    ]);

    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();
    expect($this->driver->sent)->toHaveCount(0); // delay not elapsed

    Carbon::setTestNow('2026-08-08 10:45:00');
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();

    expect($this->driver->sent)->toHaveCount(1)
        ->and($this->driver->sent[0]['message']->firstRecipient())->toBe($subscriber->email);
});

it('sends yearly date-field anniversaries and repeats next year when once is off', function (): void {
    $birthday = ListField::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'type' => ListFieldType::Date,
        'tag' => 'BIRTHDAY',
    ]);

    $campaign = autoresponder($this->list, [
        'ar_trigger' => AutoresponderTrigger::OnDateField,
        'ar_date_field_id' => $birthday->id,
        'ar_once_per_subscriber' => false,
    ]);

    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
    ]);

    SubscriberFieldValue::withoutTenancy()->create([
        'customer_id' => $this->list->customer_id,
        'subscriber_id' => $subscriber->id,
        'list_field_id' => $birthday->id,
        'value' => '1990-08-08',
    ]);

    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();
    expect($this->driver->sent)->toHaveCount(1);

    // Same year: never twice.
    Carbon::setTestNow('2026-08-09 10:00:00');
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();
    expect($this->driver->sent)->toHaveCount(1);

    // Next year's occurrence fires again (once_per_subscriber off).
    Carbon::setTestNow('2027-08-08 10:00:00');
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();
    expect($this->driver->sent)->toHaveCount(2)
        ->and(CampaignDeliveryLog::query()->where('campaign_id', $campaign->id)->count())->toBe(1);
});

it('sends after-profile-update using the row update signal', function (): void {
    $campaign = autoresponder($this->list, [
        'ar_trigger' => AutoresponderTrigger::AfterProfileUpdate,
        'ar_delay_value' => 10,
        'ar_delay_unit' => 'minutes',
    ]);

    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
    ]);

    // Untouched profile → nothing due.
    Carbon::setTestNow('2026-08-08 11:00:00');
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();
    expect($this->driver->sent)->toHaveCount(0);

    // Profile updated → due after the delay.
    $subscriber->forceFill(['ip' => '10.0.0.9'])->save();

    Carbon::setTestNow('2026-08-08 11:20:00');
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();
    expect($this->driver->sent)->toHaveCount(1);
});

it('sends after-campaign-open based on the tracked open', function (): void {
    $trigger = Campaign::factory()->create([
        'customer_id' => $this->list->customer_id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::Sent,
    ]);

    $campaign = autoresponder($this->list, [
        'ar_trigger' => AutoresponderTrigger::AfterCampaignOpen,
        'ar_trigger_campaign_id' => $trigger->id,
        'ar_delay_value' => 1,
        'ar_delay_unit' => 'hours',
    ]);

    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
    ]);

    CampaignTrackOpen::query()->create([
        'campaign_uid' => $trigger->uid,
        'subscriber_uid' => $subscriber->uid,
        'created_at' => Carbon::now(),
    ]);

    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();
    expect($this->driver->sent)->toHaveCount(0); // 1h delay pending

    Carbon::setTestNow('2026-08-08 11:30:00');
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();

    expect($this->driver->sent)->toHaveCount(1)
        ->and($this->driver->sent[0]['message']->firstRecipient())->toBe($subscriber->email);
});

it('sends after-campaign-sent based on the delivery log of the referenced campaign', function (): void {
    $trigger = Campaign::factory()->create([
        'customer_id' => $this->list->customer_id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::Sent,
    ]);

    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
    ]);

    CampaignDeliveryLog::query()->create([
        'campaign_id' => $trigger->id,
        'subscriber_id' => $subscriber->id,
        'status' => CampaignDeliveryLog::STATUS_SENT,
    ]);

    $campaign = autoresponder($this->list, [
        'ar_trigger' => AutoresponderTrigger::AfterCampaignSent,
        'ar_trigger_campaign_id' => $trigger->id,
    ]);

    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();

    expect($this->driver->sent)->toHaveCount(1)
        ->and($this->driver->sent[0]['message']->firstRecipient())->toBe($subscriber->email);
});

it('honors ar_send_time as the preferred time of day', function (): void {
    $campaign = autoresponder($this->list, [
        'ar_send_time' => '15:00:00',
    ]);

    Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'confirmed_at' => Carbon::now(),
    ]);

    // Due moment is pushed to 15:00 — nothing at 10:00.
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();
    expect($this->driver->sent)->toHaveCount(0);

    Carbon::setTestNow('2026-08-08 15:05:00');
    $this->artisan('campaigns:process-autoresponders')->assertSuccessful();
    expect($this->driver->sent)->toHaveCount(1);
});
