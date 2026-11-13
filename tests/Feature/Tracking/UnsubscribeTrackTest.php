<?php

declare(strict_types=1);

use App\Models\CampaignTrackUnsubscribe;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;

// F-1.7-003: record the campaign origin, then hand off to the §1.5 flow.
it('records the campaign unsubscribe hit and redirects into the list unsubscribe flow', function (): void {
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create();
    $subscriber = Subscriber::factory()->for($customer)->for($list, 'list')->create();

    $this->get(route('campaigns.track.unsubscribe', [
        'campaign_uid' => 'camp-55', 'subscriber_uid' => $subscriber->uid,
    ]))->assertRedirect(route('lists.public.unsubscribe', [
        'list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid,
    ]));

    /** @var CampaignTrackUnsubscribe $hit */
    $hit = CampaignTrackUnsubscribe::query()->firstOrFail();
    expect($hit->campaign_uid)->toBe('camp-55')
        ->and($hit->subscriber_uid)->toBe($subscriber->uid)
        ->and($hit->created_at)->not->toBeNull();
});

it('404s for unknown subscribers without recording anything', function (): void {
    $this->get(route('campaigns.track.unsubscribe', [
        'campaign_uid' => 'camp-55', 'subscriber_uid' => (string) \Illuminate\Support\Str::uuid(),
    ]))->assertNotFound();

    expect(CampaignTrackUnsubscribe::query()->count())->toBe(0);
});
