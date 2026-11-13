<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Events\CampaignProgressUpdated;
use App\Jobs\Sending\FlushLiveCountersJob;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Support\Stats\LiveCounter;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Event;

/**
 * F-0.1-007 — live campaign progress is broadcast from the counter flush
 * (the send path itself stays transport-free). With BROADCAST_CONNECTION=null
 * the event is a no-op, so wiring it here costs nothing until Reverb/Pusher
 * is configured.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
});

it('broadcasts progress for a campaign that is currently sending', function (): void {
    Event::fake([CampaignProgressUpdated::class]);

    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::Sending,
        'delivery_total' => 10,
        'delivery_sent' => 4,
    ]);

    app(LiveCounter::class)->increment('campaigns:'.$campaign->uid.':sent', 4);

    app(FlushLiveCountersJob::class)->handle(
        app(LiveCounter::class),
        app(\App\Services\Stats\CampaignStatsRollup::class),
    );

    Event::assertDispatched(CampaignProgressUpdated::class);
});

it('does not broadcast for campaigns that are not sending', function (): void {
    Event::fake([CampaignProgressUpdated::class]);

    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::Sent,
    ]);

    app(LiveCounter::class)->increment('campaigns:'.$campaign->uid.':sent', 1);

    app(FlushLiveCountersJob::class)->handle(
        app(LiveCounter::class),
        app(\App\Services\Stats\CampaignStatsRollup::class),
    );

    Event::assertNotDispatched(CampaignProgressUpdated::class);
});
